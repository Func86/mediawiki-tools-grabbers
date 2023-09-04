from http.server import HTTPServer, BaseHTTPRequestHandler
import urllib
import json
import os
import base64
 
host = ('0.0.0.0', 8888)
base_path = 'archives'
 
class Resquest(BaseHTTPRequestHandler):
    def do_GET(self):
        parsed = urllib.parse.urlparse(self.path)
        query_dict = urllib.parse.parse_qs(parsed.query)
        if ( query_dict.get('action', [''])[0] != 'query' or query_dict.get('list', [''])[0] != 'alldeletedrevisions'
            or query_dict.get('adrdir', [''])[0] != 'newer'
            or query_dict.get('adrprop', [''])[0] != 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel|size|sha1'
            or query_dict.get('format', [''])[0] != 'json' or query_dict.get('formatversion', [''])[0] != '2'
        ):
            print(query_dict)
            print(query_dict.get('action', [''])[0] != 'query', query_dict.get('list', [''])[0] != 'alldeletedrevisions',
                query_dict.get('adrdir', [''])[0] != 'newer',
                query_dict.get('adrprop', [''])[0] != 'ids|flags|timestamp|user|userid|comment|content|tags|contentmodel|size|sha1',
                query_dict.get('format', [''])[0] != 'json', query_dict.get('formatversion', [''])[0] != '2'
            )
            self.send_response(404)
            self.end_headers()
            return

        cont_postfix = ''
        if ('adrcontinue' in query_dict):
            cont_postfix = base64.urlsafe_b64encode(query_dict['adrcontinue'][0].encode('utf-8')).decode('utf-8')

        filename = 'archive_' + cont_postfix + '.json'
        file_path = os.path.join(base_path, filename)
        if (os.path.isfile(file_path)):
            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(open(file_path, 'rb').read())
            return
        else:
            print(file_path)

        self.send_response(404)
        self.end_headers()
 
if __name__ == '__main__':
    server = HTTPServer(host, Resquest)
    print("Starting server, listen at: %s:%s" % host)
    server.serve_forever()
