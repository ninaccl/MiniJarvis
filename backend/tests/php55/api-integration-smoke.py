import base64
import json
import os
from pathlib import Path
import urllib.error
import urllib.parse
import urllib.request
import uuid

base = os.environ.get('JARVIS_TEST_API_URL', 'http://127.0.0.1:8099/api/v1')
if urllib.parse.urlparse(base).hostname not in ('127.0.0.1', 'localhost'):
    raise SystemExit('The integration smoke only runs against a local disposable server.')

def call(method, path, body=None, token=None, expected=200):
    headers = {'Content-Type': 'application/json'}
    if token:
        headers['Authorization'] = 'Bearer ' + token
    data = None if body is None else json.dumps(body, ensure_ascii=False).encode('utf-8')
    req = urllib.request.Request(base + path, data=data, headers=headers, method=method)
    try:
        response = urllib.request.urlopen(req, timeout=15)
        status = response.status
        raw = response.read()
    except urllib.error.HTTPError as exc:
        status = exc.code
        raw = exc.read()

    try:
        value = json.loads(raw.decode('utf-8'))
    except ValueError:
        raise AssertionError('%s %s: non-JSON status=%s body=%r' % (method, path, status, raw[:500]))
    if status != expected:
        raise AssertionError('%s %s: expected %s, got %s %s' % (method, path, expected, status, value.get('error',{}).get('code','unexpected success')))
    if (expected < 400) != value['success']:
        raise AssertionError('%s %s: wrong success envelope' % (method, path))
    print('%s %s %s' % (method, path.split('?')[0], status))
    return value['data'] if value['success'] else value['error']

call('GET', '/health')
for raw in (b'{broken', b'[]'):
    request = urllib.request.Request(base + '/auth/wechat', data=raw, headers={'Content-Type':'application/json'}, method='POST')
    try:
        urllib.request.urlopen(request, timeout=15)
        raise AssertionError('Invalid JSON should return 422')
    except urllib.error.HTTPError as exc:
        if exc.code != 422 or json.loads(exc.read().decode('utf-8'))['error']['code'] != 'INVALID_JSON':
            raise AssertionError('Invalid JSON response changed')
print('POST /auth/wechat malformed JSON 422')
user = call('POST', '/auth/wechat', {'code':'dev:integration-55-' + uuid.uuid4().hex[:10],'nickname':'测试用户'})
token = user['access_token']
call('GET', '/recipes?page=1&page_size=20&q=&category_id=', token=token, expected=403)
house = call('POST', '/households', {'name':'测试家庭'}, token, expected=201)
call('GET', '/households/current', token=token)
cat = call('GET', '/categories', token=token)
recipe = call('POST', '/recipes', {'title':'番茄炒蛋','category_id':cat[0]['id'],'default_servings':2,'cover_url':None,'description':'家常菜','instructions':'炒熟','ingredients':[{'name':'番茄','quantity':'300','unit_code':'g','note':None}], 'links':[]}, token, expected=201)
recipe_id = recipe['id'] if 'id' in recipe else recipe['recipe']['id']
call('GET', '/recipes?page=1&page_size=20&q=&category_id=', token=token)
call('GET', '/recipes?q=%25_%5C', token=token)
call('GET', '/recipes/%s' % recipe_id, token=token)
batch = call('POST', '/inventory/batches', {'ingredient_name':'番茄','quantity':'500','unit_code':'g'}, token, expected=201)
batch_id = batch['id']
call('GET', '/inventory', token=token)
call('POST', '/inventory/batches/%s/movements' % batch_id, {'operation':'consume','quantity':'100','unit_code':'g'}, token, expected=201)
call('GET', '/inventory/movements', token=token)
call('GET', '/recipes/matches?count=1', token=token)
entry = call('POST', '/meal-plan/entries', {'date':'2026-10-08','meal':'dinner','recipes':[{'recipe_id':recipe_id,'servings':2}]}, token, expected=201)
call('GET', '/meal-plan?from=2026-10-08&to=2026-10-08', token=token)
shopping = call('POST', '/shopping-lists', {'selections':[{'date':'2026-10-08','meals':['dinner']}]}, token, expected=201)
shopping_id = shopping['id'] if 'id' in shopping else shopping['list']['id']
call('GET', '/shopping-lists', token=token)
call('GET', '/shopping-lists/%s' % shopping_id, token=token)
task = call('POST', '/tasks', {'title':'购买食材'}, token, expected=201)
call('GET', '/tasks', token=token)
call('GET', '/notifications/preferences', token=token)
call('PATCH', '/notifications/preferences', {'task_due':True}, token)
call('POST', '/notifications/subscription-grants', {'template_type':'task_due','result':'reject'}, token, expected=201)
call('POST', '/uploads/images', {}, token, expected=422)
boundary = 'jarvis-' + uuid.uuid4().hex
png = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lXcAAAAASUVORK5CYII=')
multipart = ('--' + boundary + '\r\nContent-Disposition: form-data; name="file"; filename="pixel.png"\r\nContent-Type: image/png\r\n\r\n').encode() + png + ('\r\n--' + boundary + '--\r\n').encode()
upload_request = urllib.request.Request(base + '/uploads/images', data=multipart, headers={'Authorization':'Bearer ' + token, 'Content-Type':'multipart/form-data; boundary=' + boundary}, method='POST')
with urllib.request.urlopen(upload_request, timeout=15) as upload_response:
    uploaded = json.loads(upload_response.read().decode('utf-8'))['data']
    if upload_response.status != 201 or not uploaded['url'].startswith('/uploads/'):
        raise AssertionError('Valid upload failed')
upload_path = Path(__file__).resolve().parents[2] / 'public' / uploaded['url'].lstrip('/')
if not upload_path.is_file():
    raise AssertionError('Uploaded file was not stored')
upload_path.unlink()
print('POST /uploads/images 201')
call('POST', '/link-previews', {'url':'http://127.0.0.1/'}, token, expected=422)
other = call('POST', '/auth/wechat', {'code':'dev:integration-55-other-' + uuid.uuid4().hex[:10]}, expected=200)
other_token = other['access_token']
call('POST', '/households', {'name':'另一家庭'}, other_token, expected=201)
call('GET', '/recipes/%s' % recipe_id, token=other_token, expected=404)
print('PHP 5.5 API integration smoke passed')
