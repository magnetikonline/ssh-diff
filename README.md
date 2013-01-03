# SSH diff
Command line utility to diff a local directory structure of files against the root of a remote server over SSH.

*Why?* Well, I like to keep production server config files under version control in local repositories ([for example](https://github.com/magnetikonline/webserverinstall.ubuntu12.04/tree/master/00root)) - this utility was created to allow for quick discovery of any file-based differences between the two. Only local files that are different/missing from remote server are checked.

Differences are found by comparing SHA1 keys of a file content, using PHP's `sha1_file()` on local filesystem and `sha1sum [filename]` on the remote server, optionally server files that are found to be different can be transferred back to your local machine over SCP for diff checking via [Meld](http://meldmerge.org/), [KDiff3](http://kdiff3.sourceforge.net/), etc.

All SSH auth is via RSA public/private keys (as should any and all SSH).

## Requires
- PHP 5.4+
- [PHP Secure Shell2 extension](http://php.net/manual/en/book.ssh2.php)
- SSH connectivity to your remote server(s) via RSA public/private keys

## Usage
Also shown by running `sshdiff.php` without command line option(s).

	Usage: sshdiff.php -s[server] -u[username] --priv-key=[file] --pub-key=[file] --root-dir=[dir] --diff-dir=[dir] -v

	<Required>
	  -s[server]           Target SSH server address/host
	  --root-dir=[dir]     Source root directory

	<Optional>
	  -p[port]             Alternative SSH port number, default is 22
	  -u[username]         User for SSH login, if not given current shell username used
	  -v                   Increase verbosity
	  --diff-dir=[dir]     If file differences found target file(s) will be placed into this directory
	  --priv-key=[file]    Private key file location if not given [/home/username/.ssh/id_rsa] used
	  --pub-key=[file]     Public key file location if not given [/home/username/.ssh/id_rsa.pub] used

## Example
Taking the following scenario:
- Local server config files in `/myserver.domain/filesystem`
- Remote server is at `myserver.domain`
- Current local user for login username (`magnetik` in this case); `~/.ssh/id_rsa` and `~/.ssh/id_rsa.pub` for auth
- Any remote file differences SCP'ed back to `/tmp/foundfilediffs`

Would/could result in something like this:

	magnetik@magnetikdev:/$ ./sshdiff.php -s myserver.domain \
		--root-dir=/myserver.domain/filesystem \
		--diff-dir=/tmp/foundfilediffs
	Remote file missing: /etc/redis.conf
	Difference found: /etc/nginx/nginx.conf
	=================================
	All done - differences were found

	magnetik@magnetikdev:/$ diff \
		/myserver.domain/filesystem/etc/nginx/nginx.conf \
		/tmp/foundfilediffs/etc/nginx/nginx.conf
