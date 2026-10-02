#!/bin/bash
# ==========================================================================
# fix-auth.sh — auth.*: CLI-guard в scripts/*.php, deny /scripts/,
# единое сообщение об ошибке входа (без перечисления пользователей).
# ==========================================================================
set -euo pipefail

W=/var/www/auth.nayanovaacademy.ru/public
GUARD='<?php if (PHP_SAPI !== "cli") { http_response_code(404); exit; } ?>'

echo "==> 1. CLI-guard в public/scripts/*.php"
for f in "$W"/scripts/*.php; do
  [ -f "$f" ] || continue
  if head -1 "$f" | grep -q 'PHP_SAPI'; then
    echo "    already: $(basename "$f")"
    continue
  fi
  tmp="$(mktemp)"
  { printf '%s\n' "$GUARD"; cat "$f"; } > "$tmp"
  chown --reference="$f" "$tmp" 2>/dev/null || true
  chmod --reference="$f" "$tmp" 2>/dev/null || true
  mv "$tmp" "$f"
  php -l "$f" >/dev/null && echo "    guarded: $(basename "$f")"
done

echo "==> 2. Единое сообщение об ошибке входа в Auth.php"
python3 - <<'PY'
p = '/var/www/auth.nayanovaacademy.ru/public/includes/Auth.php'
s = open(p, encoding='utf-8').read()
old1 = "return ['success' => false, 'error' => 'Пользователь не найден'];"
old2 = "return ['success' => false, 'error' => 'Неверный пароль'];"
new = "return ['success' => false, 'error' => 'Неверный логин или пароль'];"
n = 0
if old1 in s:
    s = s.replace(old1, new); n += 1
if old2 in s:
    s = s.replace(old2, new); n += 1
# если уже унифицировано — не трогаем
open(p, 'w', encoding='utf-8').write(s)
print(f'    replaced {n} branch(es)')
PY
php -l "$W/includes/Auth.php" >/dev/null && echo "    Auth.php OK"

echo "==> 3. nginx: deny /scripts/ (auth.*)"
CONF=/etc/nginx/sites-enabled/auth.nayanovaacademy.ru
python3 - "$CONF" <<'PY'
import sys
p = sys.argv[1]
s = open(p, encoding='utf-8').read()
if 'location ^~ /scripts/' in s:
    print('    deny /scripts already present')
else:
    block = '''    location ^~ /scripts/ {
        deny all;
        access_log off;
        log_not_found off;
    }

'''
    marker = '    location ~ /\\. {'
    idx = s.find(marker)
    if idx == -1:
        raise SystemExit('marker not found')
    s = s[:idx] + block + s[idx:]
    open(p, 'w', encoding='utf-8').write(s)
    print('    inserted deny /scripts')
PY
nginx -t
systemctl reload nginx
echo "==> done"
