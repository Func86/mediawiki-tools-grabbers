#!/usr/bin/python3

"""
    get_all_deleted_revisions.py

    MediaWiki API Demos
    Demo of `alldeletedrevisions` module: List all deleted revisions from a User.

    MIT License
"""
import requests
import sys
import base64
import os

S = requests.Session()

URL = "https://zh.moegirl.org.cn/api.php"
# Step1: Retrieve login token
PARAMS_0 = {
    'action':"query",
    'meta':"tokens",
    'type':"login",
    'format':"json"
}

R = S.get(url=URL, params=PARAMS_0)
DATA = R.json()

LOGIN_TOKEN = DATA['query']['tokens']['logintoken']

# Step2: Send a post request to login. Use of main account for login is not
# supported. Obtain credentials via Special:BotPasswords
# (https://www.mediawiki.org/wiki/Special:BotPasswords) for lgname & lgpassword
PARAMS_1 = {
    'action':"login",
    'lgname':"", # bot pass name
    'lgpassword':"", #bot pass
    'lgtoken':LOGIN_TOKEN,
    'format':"json"
}

R = S.post(URL, data=PARAMS_1)

# Step 3: Send a get request to get all the deleted revisions

PARAMS_2 = {
    "action": "query",
    "list": "alldeletedrevisions",
    'adrdir': 'newer', # Grab old revisions first
    "adrprop": "ids|flags|timestamp|user|userid|comment|content|tags|contentmodel|size|sha1",
    # All existing NS, in case unused ones cause errors when using the dump
    'adrnamespace': '0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|274|275|710|711|828|829|2300|2301|2302|2303',
    'adrslots': 'main',
    # 'adrlimit': 10, # test
    'adrlimit': 'max', # XXX: too many for the server?
    'format': 'json',
    'formatversion': 2
}

# frist param for adrcontinue, in case aborted
if len(sys.argv) == 2:
    PARAMS_2['continue'] = '-||'
    PARAMS_2['adrcontinue'] = sys.argv[1]

os.makedirs('archives', exist_ok=True)

while True:
    try:
        R = S.get(url=URL, params=PARAMS_2)
        R.raise_for_status()
    except requests.exceptions.RequestException as e:
        print(f"Error retrieving data: {e}")
        print('Please resume from the last adrcontinue.')
        break

    # XXX: too long for the file system?
    path = 'archives/archive_' + base64.urlsafe_b64encode(PARAMS_2.get('adrcontinue', '').encode('utf-8')).decode('utf-8') + '.json'
    open(path, 'wb').write(R.content)

    DATA = R.json()
    if 'continue' in DATA:
        PARAMS_2['continue'] = DATA['continue']['continue']
        PARAMS_2['adrcontinue'] = DATA['continue']['adrcontinue']
        print( 'adrcontinue: ' + PARAMS_2['adrcontinue'])
    else:
        break

    # XXX: WAF? sleep? proxy? UA?
