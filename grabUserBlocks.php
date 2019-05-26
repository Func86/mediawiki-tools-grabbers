<?php
/**
 * Maintenance script to grab the user block data from a wiki (to which we have
 * only read-only access instead of full database access).
 *
 * @file
 * @ingroup Maintenance
 * @author Jack Phoenix
 * @author Jesús Martínez <martineznovo@gmail.com>
 * @version 1.1
 * @date 5 August 2019
 * @note Based on code by:
 * - Legoktm & Uncyclopedia development team, 2013 (blocks_table.py)
 */

use MediaWiki\MediaWikiServices;

require_once 'includes/ExternalWikiGrabber.php';

class GrabUserBlocks extends ExternalWikiGrabber {

	public function __construct() {
		parent::__construct();
		$this->mDescription = 'Grabs user block data from a pre-existing wiki into a new wiki.';
		$this->addOption( 'startdate', 'Start point (20121222142317, 2012-12-22T14:23:17Z, etc).', false, true );
		$this->addOption( 'enddate', 'End point (20121222142317, 2012-12-22T14:23:17Z, etc); defaults to current timestamp.', false, true );
	}

	public function execute() {
		parent::execute();

		$startDate = $this->getOption( 'startdate' );
		if ( $startDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $startDate ) ) {
				$this->fatalError( 'Invalid startdate format.' );
			}
		}
		$endDate = $this->getOption( 'enddate' );
		if ( $endDate ) {
			if ( !wfTimestamp( TS_ISO_8601, $endDate ) ) {
				$this->fatalError( 'Invalid enddate format.' );
			}
		} else {
			$endDate = wfTimestampNow();
		}

		$params = [
			'list' => 'blocks',
			'bkdir' => 'newer',
			'bkend' => $endDate,
			'bklimit' => 'max',
			'bkprop' => 'id|user|userid|by|byid|timestamp|expiry|reason|range|flags',
		];

		$more = true;
		$bkstart = $startDate;
		$i = 0;

		$this->output( "Grabbing blocks...\n" );
		do {
			if ( $bkstart === null ) {
				unset( $params['bkstart'] );
			} else {
				$params['bkstart'] = $bkstart;
			}

			$result = $this->bot->query( $params );

			if ( empty( $result['query']['blocks'] ) ) {
				$this->fatalError( 'No blocks, hence nothing to do. Aborting the mission.' );
			}

			foreach ( $result['query']['blocks'] as $logEntry ) {
				// Skip autoblocks, nothing we can do about 'em
				if ( !isset( $logEntry['automatic'] ) ) {
					$this->processEntry( $logEntry );
					$i++;
				}

				if ( isset( $result['query-continue'] ) ) {
					$bkstart = $result['query-continue']['blocks']['bkstart'];
					$this->output( "{$i} entries processed.\n" );
				} else {
					$bkstart = null;
				}

				$more = !( $bkstart === null );
			}

			# Readd the AUTO_INCREMENT here
		} while ( $more );

		$this->output( "Done: $i entries processed\n" );
	}

	public function processEntry( $entry ) {
		$ts = wfTimestamp( TS_MW, $entry['timestamp'] );

		$userIdentity = $this->getUserIdentity( $entry['byid'], $entry['by'] );
		$performer = User::newFromIdentity( $userIdentity );

		$commentStore = MediaWikiServices::getInstance()->getCommentStore();
		$commentFields = $commentStore->insert( $this->dbw, 'ipb_reason', $entry['reason'] );
		$actorMigration = ActorMigration::newMigration();
		$actorFields = $actorMigration->getInsertValues( $this->dbw, 'ipb_by', $performer );

		$data = [
			'ipb_id' => $entry['id'],
			'ipb_address' => $entry['user'],
			'ipb_user' => $entry['userid'],
			#'ipb_by' => $entry['byid'],
			#'ipb_by_text' => $entry['by'],
			#'ipb_reason' => $entry['reason'],
			'ipb_timestamp' => $ts,
			'ipb_auto' => 0,
			'ipb_anon_only' => isset( $entry['anononly'] ),
			'ipb_create_account' => isset( $entry['nocreate'] ),
			'ipb_enable_autoblock' => isset( $entry['autoblock'] ),
			'ipb_expiry' => ( $entry['expiry'] == 'infinity' ? $this->dbw->getInfinity() : wfTimestamp( TS_MW, $entry['expiry'] ) ),
			'ipb_range_start' => ( isset( $entry['rangestart'] ) ? $entry['rangestart'] : false ),
			'ipb_range_end' => ( isset( $entry['rangeend'] ) ? $entry['rangeend'] : false ),
			'ipb_deleted' => isset( $entry['hidden'] ),
			'ipb_block_email' => isset( $entry['noemail'] ),
			'ipb_allow_usertalk' => isset( $entry['allowusertalk'] ),
		] + $commentFields + $actorFields;
		$this->dbw->insert( 'ipblocks', $data, __METHOD__ );
		$this->dbw->commit();
	}
}

$maintClass = 'GrabUserBlocks';
require_once RUN_MAINTENANCE_IF_MAIN;
