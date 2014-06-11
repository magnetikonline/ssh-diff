# SSH diff
Command line utility to diff a local directory structure of files against the root of a remote server over SSH.

*Why?* Well, I like to keep production server config files under version control in local repositories ([example](https://github.com/magnetikonline/webserverinstall.ubuntu12.04/tree/master/00root)) - this utility was created to allow for quick discovery of any file-based differences between the two. Only local files that are different or missing from the remote server are checked for.

Differences are found by comparing SHA1 keys of a file content, using PHP's `sha1_file()` on local filesystem and `sha1sum [filename]` on the remote server, optionally server files that are found to be different can be transferred back to your local machine over SCP for diff checking via [Meld](http://meldmerge.org/), [KDiff3](http://kdiff3.sourceforge.net/), etc.

All SSH auth is via RSA public/private keys (as should any and all SSH).

## Requires
- PHP 5.4+
- [PHP Secure Shell2 extension](http://php.net/manual/en/book.ssh2.php)
- SSH connectivity to your remote server(s) via RSA public/private keys

## Usage
Also shown by running `sshdiff.php` without command line option(s).

	Usage:
	  sshdiff.php -s SERVER --root-dir DIR

	Required:
	  -s SERVER        Target SSH server address/host
	  --root-dir DIR   Source root directory

	Optional:
	  -p PORT          Alternative SSH port number, default is 22
	  -u USERNAME      User for SSH login, if not given current shell username used
	  -v               Increase verbosity
	  --diff-dir DIR   If file differences found target file(s) will be placed into this directory
	  --priv-key FILE  Private key file location - default [/home/username/.ssh/id_rsa]
	  --pub-key FILE   Public key file location - default [/home/username/.ssh/id_rsa.pub]

## Example
Taking the following scenario:
- Local server config files in `/myserver.domain/filesystem`
- Remote server is at `myserver.domain`
- Current local user for login username (`magnetik` in this case); `~/.ssh/id_rsa` and `~/.ssh/id_rsa.pub` for auth
- Any remote file differences SCP'ed back to `/tmp/diffs`

Would/could result in something like this:

	$ ./sshdiff.php -s myserver.domain \
		--root-dir /myserver.domain/filesystem \
		--diff-dir /tmp/diffs
	Missing: /etc/redis.conf
	Difference: /etc/nginx/nginx.conf
	=================================
	All done - differences were found

	$ diff \
		/myserver.domain/filesystem/etc/nginx/nginx.conf \
		/tmp/diffs/etc/nginx/nginx.conf
