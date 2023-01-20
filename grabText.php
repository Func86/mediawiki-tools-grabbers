<?php
/**
 * Maintenance script to grab text from a wiki and import it to another wiki.
 * Translated from Edward Chernenko's Perl version (text.pl).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix <jack@shoutwiki.com>
 * @author Calimonious the Estrange
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.1
 * @date 5 August 2019
 */

use MediaWiki\MediaWikiServices;

require_once 'includes/TextGrabber.php';

class GrabText extends TextGrabber {

	private $batching;

	public function __construct() {
		parent::__construct();
		$this->mDescription = "Grab text from an external wiki and import it into one of ours.\nDon't use this on a large wiki unless you absolutely must; it will be incredibly slow.";
		$this->addOption( 'start', 'Page at which to start, useful if the script stopped at this point', false, true );
		$this->addOption( 'namespaces', 'Pipe-separated namespaces (ID) to grab. Defaults to all namespaces', false, true );
	}

	public function execute() {
		parent::execute();

		$this->output( "\n" );

		# Get all pages as a list, start by getting namespace numbers...
		$this->output( "Retrieving namespaces list...\n" );

		$params = [
			'meta' => 'siteinfo',
			'siprop' => 'namespaces|statistics|namespacealiases'
		];
		$result = $this->bot->query( $params );
		$siteinfo = $result['query'];

		# No data - bail out early
		if ( empty( $siteinfo ) ) {
			$this->fatalError( 'No siteinfo data found' );
		}

		$textNamespaces = [];
		if ( $this->hasOption( 'namespaces' ) ) {
			$textNamespaces = explode( '|', $this->getOption( 'namespaces', '' ) );
			$grabFromAllNamespaces = false;
		} else {
			$grabFromAllNamespaces = true;
			foreach ( array_keys( $siteinfo['namespaces'] ) as $ns ) {
				# Ignore special
				if ( $ns >= 0 ) {
					$textNamespaces[] = $ns;
				}
			}
		}
		if ( !$textNamespaces ) {
			$this->fatalError( 'Got no namespaces' );
		}

		if ( $grabFromAllNamespaces ) {
			# Get list of live pages from namespaces and continue from there
			$pageCount = $siteinfo['statistics']['pages'];
			$this->output( "Generating page list from all namespaces - $pageCount expected...\n" );
		} else {
			$this->output( sprintf( "Generating page list from %s namespaces...\n", count( $textNamespaces ) ) );
		}

		$start = $this->getOption( 'start' );
		if ( $start ) {
			$title = Title::newFromText( $start );
			if ( is_null( $title ) ) {
				$this->fatalError( 'Invalid title provided for the start parameter' );
			}
			$this->output( sprintf( "Trying to resume import from page %s\n", $title ) );
		}

		$this->batching = 500 / round( $siteinfo['statistics']['edits'] / $siteinfo['statistics']['pages'] );

		$pageCount = 0;
		foreach ( $textNamespaces as $ns ) {
			$continueTitle = null;
			if ( isset( $title ) && !is_null( $title ) ) {
				if ( $title->getNamespace() === (int)$ns ) {
					# The apfrom parameter doesn't have namespace!!
					$continueTitle = $title->getText();
					$title = null;
				} else {
					continue;
				}
			}
			$pageCount += $this->processPagesFromNamespace( (int)$ns, $continueTitle );
		}
		$this->output( "\nDone - found $pageCount total pages.\n" );
		# Done.
	}

	/**
	 * Grabs all pages from a given namespace
	 *
	 * @param int $ns Namespace to process.
	 * @param string $continueTitle Title to start from (optional).
	 * @return int Number of pages processed.
	 */
	function processPagesFromNamespace( $ns, $continueTitle = null ) {
		$this->output( "Processing pages from namespace $ns...\n" );
		$doneCount = 0;
		$nsPageCount = 0;
		$more = true;
		$params = [
			'generator' => 'allpages',
			'gaplimit' => 'max',
			'prop' => 'info',
			'inprop' => 'protection',
			'gapnamespace' => $ns
		];
		if ( $continueTitle ) {
			$params['gapfrom'] = $continueTitle;
		}
		do {
			$result = $this->bot->query( $params );

			# Skip empty namespaces
			if ( isset( $result['query'] ) ) {
				$pages = $result['query']['pages'];

				$resultsCount = 0;
				$chunked = array_chunk( $pages, $this->batching );
				foreach ( $chunked as $pageList ) {
					$this->processPages( $pageList );
					$doneCount += $this->batching;
					if ( $doneCount % 500 <= $this->batching ) {
						$this->output( "$doneCount\n" );
					}
					$resultsCount++;
				}
				$nsPageCount += $resultsCount;

				# Add continuation parameters
				if ( isset( $result['continue'] ) ) {
					$params = array_merge( $params, $result['continue'] );
				} else {
					$more = false;
				}
			} else {
				$more = false;
			}
		} while ( $more );

		$this->output( "$nsPageCount pages found in namespace $ns.\n" );

		return $nsPageCount;
	}

	/**
	 * Process a chunk of pages.
	 */
	private function processPages( $pages ) {
		$pageIds = [];
		$info_pages = [];
		foreach( $pages as $page ) {
			$pageIds[] = $page['pageid'];
			$info_pages[$page['pageid']] = $page;
		}
		$pageIds = implode( '|', $pageIds );

		$this->output( "Query revisions for page id $pageIds...\n" );

		$params = [
			'pageids' => $pageIds,
			'prop' => 'revisions',
			'rvlimit' => 'max',
			'rvprop' => 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel',
			'rvdir' => 'newer',
			'rvend' => wfTimestamp( TS_ISO_8601, $this->endDate )
		];

		$last_pageId = null;
		$exists = [];
		$processed = [];
		while ( true ) {
			$result = $this->bot->query( $params );

			if ( !$result || isset( $result['error'] ) ) {
				if ( isset( $result['error'] ) ) {
					$this->fatalError( "Error getting revision information from API: " . json_encode( $result['error'] ) . '.' );
				}
				else {
					$this->fatalError( "Error getting revision information from API." );
				}
				return;
			}

			$rev_pages = array_values( $result['query']['pages'] );

			foreach( $rev_pages as $rev_page ) {
				$pageId = $page['pageid'];
				if ( $last_pageId !== $pageId ) {
					if ( $last_pageId && $processed[$last_pageId] ) {
						$this->insertOrUpdatePage( $info_pages[$last_pageId], $exists[$last_pageId] );
					}
					$last_pageId = $pageId;
					$exists[$pageId] = $this->preparePage( $info_pages[$pageId] );
					$processed[$pageId] = false;
				}

				foreach ( $rev_page['revisions'] as $revision ) {
					$processed[$pageId] = $this->processRevision( $revision, $pageId, $title ) || $processed[$pageId];
				}
			}

			# Add continuation parameters
			if ( isset( $result['continue'] ) ) {
				$params = array_merge( $params, $result['continue'] );
			} else {
				break;
			}
		}
		if ( $last_pageId && $processed[$last_pageId] ) {
			$this->insertOrUpdatePage( $info_pages[$last_pageId], $exists[$last_pageId] );
		}
		$this->dbw->commit();
	}

	private function preparePage( $info_page ) {
		$pageID = $info_page['pageid'];

		# Check if page is present
		$pageIsPresent = false;
		$rowCount = $this->dbw->selectRowCount(
			'page',
			'page_id',
			[ 'page_id' => $pageID ],
			__METHOD__
		);
		if ( $rowCount ) {
			$pageIsPresent = true;
		}

		# If page is not present, check if title is present, because we can't insert
		# a duplicate title. That would mean the page was moved leaving a redirect but
		# we haven't processed the move yet
		if ( !$pageIsPresent ) {
			$conflictingPageID = $this->getPageID( $info_page['namespace'], $info_page['title'] );
			if ( $conflictingPageID ) {
				# Whoops...
				$this->resolveConflictingTitle( $conflictingPageID, $info_page['namespace'], $info_page['title'] );
			}
		}

		# Update page_restrictions (only if requested)
		if ( isset( $info_page['protection'] ) ) {
			$this->output( "Setting page_restrictions on page_id $pageID.\n" );
			# Delete first any existing protection
			$this->dbw->delete(
				'page_restrictions',
				[ 'pr_page' => $pageID ],
				__METHOD__
			);
			# insert current restrictions
			foreach ( $info_page['protection'] as $prot ) {
				# Skip protections inherited from cascade protections
				if ( !isset( $prot['source'] ) ) {
					$e = [
						'page' => $pageID,
						'type' => $prot['type'],
						'level' => $prot['level'],
						'cascade' => (int)isset( $prot['cascade'] ),
						'expiry' => ( $prot['expiry'] == 'infinity' ? 'infinity' : wfTimestamp( TS_MW, $prot['expiry'] ) )
					];
					$this->dbw->insert(
						'page_restrictions',
						[
							'pr_page' => $e['page'],
							'pr_type' => $e['type'],
							'pr_level' => $e['level'],
							'pr_cascade' => $e['cascade'],
							'pr_expiry' => $e['expiry']
						],
						__METHOD__
					);
				}
			}
		}

		return $pageIsPresent;
	}

	private function insertOrUpdatePage( $page_e, $pageIsPresent ) {
		# Trim and convert displayed title to database page title
		$page_e = [
			'namespace' =>  $info_page['ns'],
			'title' => $this->sanitiseTitle( $info_page['ns'], $info_page['title'] ),
			'counter' => ( isset( $info_page['counter'] ) ? $info_page['counter'] : 0 ),
			'is_redirect' => ( isset( $info_page['redirect'] ) ? 1 : 0 ),
			'is_new' => ( isset( $info_page['new'] ) ? 1 : 0 ),
			'random' => wfRandom(),
			'touched' => wfTimestampNow(),
			'len' => $info_page['length'],
			'latest' => $info_page['lastrevid'],
			'content_model' => null
		];

		# We kind of need this to resume...
		$this->output( "Title: {$page_e['title']} in namespace {$page_e['namespace']}\n" );
		$title = Title::makeTitle( $page_e['namespace'], $page_e['title'] );

		$defaultModel = null;
		if ( isset( $info_page['contentmodel'] ) ) {
			# This would be the most accurate way of getting the content model for a page.
			# However it calls hooks and can be incredibly slow or cause errors
			#$defaultModel = ContentHandler::getDefaultModelFor( $title );
			$defaultModel = MediaWikiServices::getInstance()->getNamespaceInfo()->
				getNamespaceContentModel( $info_page['ns'] ) || CONTENT_MODEL_WIKITEXT;
			# Set only if not the default content model
			if ( $defaultModel != $info_page['contentmodel'] ) {
				$page_e['content_model'] = $info_page['contentmodel'];
			}
		}

		$insert_fields = [
			'page_namespace' => $page_e['namespace'],
			'page_title' => $page_e['title'],
			'page_is_redirect' => $page_e['is_redirect'],
			'page_is_new' => $page_e['is_new'],
			'page_random' => $page_e['random'],
			'page_touched' => $page_e['touched'],
			'page_latest' => $page_e['latest'],
			'page_len' => $page_e['len'],
			'page_content_model' => $page_e['content_model']
		];
		if ( $this->supportsCounters && $page_e['counter'] ) {
			$insert_fields['page_counter'] = $page_e['counter'];
		}
		if ( !$pageIsPresent ) {
			# insert if not present
			$this->output( "Inserting page entry $pageID\n" );
			$insert_fields['page_id'] = $pageID;
			$this->dbw->insert(
				'page',
				$insert_fields,
				__METHOD__
			);
		} else {
			# update existing
			$this->output( "Updating page entry $pageID\n" );
			$this->dbw->update(
				'page',
				$insert_fields,
				[ 'page_id' => $pageID ],
				__METHOD__
			);
		}
	}
}

$maintClass = 'GrabText';
require_once RUN_MAINTENANCE_IF_MAIN;
