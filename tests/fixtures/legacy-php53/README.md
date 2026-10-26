# Legacy receipts written by the original code on PHP 5.3

Genuine legacy receipt files `files/<n>.aes`, written by the original upload
code of pgacc (commit `6458a4d`, the last commit with `mcrypt`; its
encryption lines are unchanged since 2013) on PHP 5.3.3 with `mcrypt`, as on
the old production server. Synthetic content only. Used by
`tests/unit/ReceiptMigrationTest.php` (`testGenuineLegacyFilesOfTheOriginalCode`):
the legacy read and every migrated `<n>.v2.aes` must give exactly the bytes
the original download sent.

| File | Role |
|---|---|
| `inputs/` | the uploaded files (synthetic PDFs, JPEGs, a PNG, an empty file) |
| `cases.txt` | file number, voucher ID at the upload, input file |
| `files/<n>.aes` | the files the original `page_new_file()` wrote |
| `downloads.txt` | Content-Type, length and SHA-256 of what the original `download_file()` sent |
| `generate.sh`, `request.php`, `Dockerfile`, `fixture-constants.php` | the generator |

The cases: a small PDF and one whose size is a multiple of the 32-byte block,
a small JPEG, files that end in zero bytes (fewer or more than one block), an
empty file, a PNG, a JPEG with data after its end, a PDF with 2 KiB after
`%%EOF`, a 32-byte PDF, two receipts of one voucher, and file 11, whose
voucher 711 was merged into voucher 799 after the upload (the merge
statement of `page_edit_merge()`). `downloads.txt` has file 11 both before
the merge (`11-before-merge`, the receipt) and after it (what the legacy
download sends since then).

## Generation

```sh
sh tests/fixtures/legacy-php53/generate.sh
```

It needs Docker and the git history. It builds the PHP 5.3.3 image of
`Dockerfile` (Debian 6 squeeze, i386, the package versions of the old
server), extracts `new.php`, `edit.php`, `file.php` and `functions.php` of
commit `6458a4d`, and starts a PostgreSQL 18 container with
`tests/fixtures/schema.sql` on an internal network. For each case it inserts
a voucher row, sets the `file_number` sequence to the number of `cases.txt`,
and runs `request.php` through `php-cgi` (the production server API), which
includes `fixture-constants.php` (a configured `$key`, as `index.php`
includes `constants.php`) and calls the original `page_new_file()`. Then it downloads file 11 with the original
`download_file()`, merges its voucher, downloads every file, and writes
`files/` and `downloads.txt`. The output is deterministic; a rerun must
leave `git status` clean. Remove the image afterwards with
`docker rmi pgacc-legacy-php53`.
