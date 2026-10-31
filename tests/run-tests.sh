#!/bin/bash
# Jalankan suite tes WordPress uji dengan server php -S sendiri di 127.0.0.1:8791.
# Server (dan sendmail turunannya) selalu dimatikan saat skrip selesai (trap), termasuk bila gagal/diinterupsi.
# Pemakaian: [VDPG_TEST_WP=/path/wordpress] [WP_CLI="php /path/wp-cli.phar"] tests/run-tests.sh [suite...]
# Port tetap 8791 karena siteurl WordPress uji memakai port itu. Lihat tests/README.md.
set -u
PORT=8791
DIR=$(cd "$(dirname "$0")" && pwd)
# Bawaan: folder wordpress di sebelah folder skrip ini (pakai $PWD logis, jadi berlaku juga lewat symlink).
WP=${VDPG_TEST_WP:-$(cd "$(dirname "$0")/.." && pwd)/wordpress}
if [ -z "${WP_CLI:-}" ]; then
    if [ -f "$WP/../wp" ]; then WP_CLI="php $(cd "$WP/.." && pwd)/wp"; else WP_CLI=wp; fi
fi
SUITES=("$@")
[ ${#SUITES[@]} -eq 0 ] && SUITES=(toko-e2e log-mask e2e mp-e2e donasi-e2e duitku-amount checkout-js)

if [ ! -f "$WP/wp-load.php" ]; then
    echo "WordPress uji tidak ditemukan di $WP (atur VDPG_TEST_WP)." >&2
    exit 2
fi

port_busy() { (exec 3<>/dev/tcp/127.0.0.1/$PORT) 2>/dev/null; }
# Pola diawali ^ supaya tidak mengenai proses lain yang cmdline-nya memuat teks ini.
# sendmail yang dipanggil wp_mail() di sisi server mewarisi socket port ini; ikut dimatikan.
kill_server() {
    for p in $(pgrep -f "^php -S 127.0.0.1:$PORT"); do kill "$p" 2>/dev/null; done
    for p in $(lsof -t -nP -iTCP:$PORT -sTCP:LISTEN 2>/dev/null); do
        case "$(ps -o comm= -p "$p")" in sendmail|dotlockfi*) kill "$p" 2>/dev/null;; esac
    done
}

kill_server
for i in $(seq 1 20); do port_busy || break; sleep 0.5; done
if port_busy; then
    echo "Port $PORT masih dipakai proses lain; tes dibatalkan." >&2
    exit 2
fi

cd "$WP" || exit 2
setsid nohup timeout 900 php -S 127.0.0.1:$PORT >/tmp/php$PORT.log 2>&1 &
trap kill_server EXIT INT TERM
for i in $(seq 1 40); do curl -s -o /dev/null "http://127.0.0.1:$PORT/" && break; sleep 0.5; done

rc=0
for s in "${SUITES[@]}"; do
    echo "== $s"
    if [ -f "$DIR/$s.mjs" ]; then
        # Suite browser (Node + Playwright); lihat komentar di kepala berkasnya.
        out=$(WP="$WP" WP_CLI="$WP_CLI" node "$DIR/$s.mjs" 2>&1)
    else
        out=$($WP_CLI --allow-root eval-file "$DIR/$s.php" 2>&1)
    fi
    echo "$out" | grep -E "GAGAL|Fatal|^Hasil"
    # Pola utuh: "10 gagal" tidak boleh dianggap "0 gagal".
    echo "$out" | grep -qE "^Hasil: [0-9]+ lulus, 0 gagal" || rc=1
done
exit $rc
