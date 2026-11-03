"""Short-lived, request-bound assertions from Hestia's server-side session check."""
import base64
import hashlib
import hmac
import json
import re
import time

def validate_assertion(token,secret,method,uri,now=None,allowed_roles=('admin',),expected_user=None):
    if not isinstance(token,str) or len(token)>4096 or not secret:return None
    try:
        encoded,signature=token.split('.',1)
        expected=hmac.new(secret.encode(),encoded.encode('ascii'),hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected,signature):return None
        data=json.loads(base64.urlsafe_b64decode(encoded+'='*(-len(encoded)%4)))
        timestamp=data.get('ts');current=int(time.time() if now is None else now)
        if type(timestamp) is not int or not -5<=current-timestamp<=30:return None
        if data.get('role') not in allowed_roles or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_.-]{0,31}',data.get('user','')):return None
        if expected_user is not None and data.get('user')!=expected_user:return None
        if data.get('method')!=method or data.get('uri')!=uri:return None
        if not re.fullmatch(r'[a-f0-9]{32,128}',data.get('csrf','')):return None
        return data
    except (ValueError,TypeError,UnicodeError,AttributeError):return None
