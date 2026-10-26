#!/bin/sh
set -eu

# Regenerates files/<n>.aes and downloads.txt with the original pgacc upload
# and download code (commit 6458a4d) on PHP 5.3.3 with mcrypt, through
# php-cgi as on the old production server (README.md). Needs Docker and the
# git history; synthetic inputs only (inputs/, cases.txt).
#
#   sh tests/fixtures/legacy-php53/generate.sh

fixture=$(cd "$(dirname "$0")" && pwd)
repo=$(cd "$fixture/../../.." && pwd)
id=pgacc-legacy-php53-$$
image=pgacc-legacy-php53
# Under build/ (ignored by git), so that the Docker daemon can mount it.
mkdir -p "$repo/build"
work=$(mktemp -d "$repo/build/legacy-php53.XXXXXX")
cleanup()
{
  docker rm -f "$id-db" >/dev/null 2>&1 || true
  docker network rm "$id" >/dev/null 2>&1 || true
  rm -rf "$work"
}
trap cleanup EXIT

docker build --quiet -t "$image" "$fixture" >/dev/null
mkdir -p "$work/app" "$work/files" "$work/out"
chmod 777 "$work/files" "$work/out"
git -C "$repo" archive 6458a4d new.php edit.php file.php functions.php | tar -x -C "$work/app"

docker network create --internal "$id" >/dev/null
docker run -d --name "$id-db" --network "$id" -e POSTGRES_HOST_AUTH_METHOD=trust -e POSTGRES_DB=accounting \
  postgres:18-bookworm@sha256:bbf83594798e144bff365a9c191c5d779f181fb3135902064ab8ff11918020fc >/dev/null
until docker exec "$id-db" pg_isready -q -U postgres -d accounting 2>/dev/null; do sleep 1; done
sleep 2
psql()
{
  docker exec -i "$id-db" psql -q -tA -v ON_ERROR_STOP=1 -U postgres -d accounting "$@"
}
# psql -c without input (inside the read loops, stdin is cases.txt).
sql()
{
  psql "$@" </dev/null
}
psql <"$repo/tests/fixtures/schema.sql" >/dev/null

# php-cgi in the PHP 5.3 image; the work directory holds request.php's
# relative files/ (php-cgi changes into the script's directory).
php53()
{
  cp "$fixture/request.php" "$work/request.php"
  docker run --rm --network "$id" -v "$work/app:/app:ro" -v "$fixture:/fixture:ro" -v "$work:/work" -w /work \
    -e REDIRECT_STATUS=1 -e REQUEST_METHOD=GET -e SCRIPT_FILENAME=/work/request.php \
    -e RX_DATABASE="host=$id-db dbname=accounting user=postgres" "$@"
}

# Uploads: one voucher row per case; file_number is set so that the file
# gets the number of cases.txt.
grep -v '^#' "$fixture/cases.txt" | while read -r number voucher input; do
  row=$((9000 + number))
  sql -c "INSERT INTO vouchers (id, voucher_id, type, orga, member, amount, account, comment, committed, date,
    person_type, file, save_user, vaccount) VALUES ($row, $voucher, 20, 2, false, -1000, 'SYN', 'synthetic $input',
    true, timestamp '2013-07-12', 1, 0, 1, 'SYN')" -c "SELECT setval('file_number', $number, false)" >/dev/null
  php53 -e QUERY_STRING="action=newfile&id=$voucher" -e RX_MODE=upload -e RX_BID=$row \
    -e RX_SOURCE=/fixture/inputs/$input "$image" php-cgi >"$work/out/upload-$number" 2>"$work/out/upload-$number.err" || true
  grep -q "^Location:" "$work/out/upload-$number" || { echo "upload of $input did not redirect:" >&2; cat "$work/out/upload-$number" "$work/out/upload-$number.err" >&2; exit 1; }
  [ "$(sql -c "SELECT file FROM vouchers WHERE id = $row")" = "$number" ] || { echo "$input: wrong file number" >&2; exit 1; }
done

# Downloads: the body of the original download_file() response.
download()
{
  php53 -e QUERY_STRING="action=file&id=$((9000 + $1))" -e RX_MODE=download "$image" \
    sh -c 'php-cgi >/work/out/response 2>/dev/null; php -r '\''$r = file_get_contents("/work/out/response");
      $at = strpos($r, "\r\n\r\n"); preg_match("/^Content-Type: (\\S+)/mi", substr($r, 0, $at), $m);
      file_put_contents("/work/out/body", substr($r, $at + 4)); echo $m[1];'\'''
}
{
  echo "# <name> <Content-Type> <bytes> <sha256> of the original download"
  type=$(download 11)
  echo "11-before-merge $type $(wc -c <"$work/out/body") $(sha256sum "$work/out/body" | cut -d ' ' -f 1)"
  # The merge statement of page_edit_merge(): voucher 711 into 799.
  sql -c 'UPDATE vouchers SET voucher_id = 799 WHERE voucher_id = 711' >/dev/null
  grep -v '^#' "$fixture/cases.txt" | while read -r number voucher input; do
    type=$(download "$number")
    echo "$number $type $(wc -c <"$work/out/body") $(sha256sum "$work/out/body" | cut -d ' ' -f 1)"
  done
} >"$work/downloads.txt"

rm -rf "$fixture/files"
mkdir "$fixture/files"
cp "$work"/files/*.aes "$fixture/files/"
chmod 644 "$fixture"/files/*.aes
cp "$work/downloads.txt" "$fixture/downloads.txt"
echo "Wrote $(ls "$fixture/files" | wc -l) files and downloads.txt"
