#!/usr/bin/env php
<?php
// sshdiff.php



class SSHDiff {

	const LE = "\n";
	const SHELL_SHA1_SPRINTF = '[ -f "%1$s" ] && sha1sum "%1$s"';



	public function __construct(array $argv) {

		// fetch command line options and validate given dirs - exit on error
		if (
			(($optionList = $this->getOptions($argv)) === false) ||
			(!$this->validatePaths($optionList))
		) exit(1);

		// connect to target server via SSH - exit on error
		if (($sshSession = $this->sshConnect($optionList)) === false) exit(1);

		// start processing
		$differencesFound = $this->process($optionList,$sshSession);

		// close SSH connection
		$this->sshExec($sshSession,'logout');

		// all done
		$summaryText = 'All done - ' . (($differencesFound) ? 'differences were found' : 'no differences');
		$this->writeLine(
			self::LE . str_repeat('=',strlen($summaryText)) . self::LE .
			$summaryText
		);

		// exit with error code two if diffs found
		exit(($differencesFound) ? 2 : 0);
	}

	private function getOptions(array $argv) {

		$optionList = getopt('p:s:u:v',['diff-dir:','priv-key:','pub-key:','root-dir:']);

		// required options given?
		if (!isset($optionList['s'],$optionList['root-dir'])) {
			// no - display usage
			$this->writeLine(
				'Usage: ' . basename($argv[0]) . ' -s[server] -u[username] --priv-key=[file] --pub-key=[file] --root-dir=[dir] --diff-dir=[dir] -v' . self::LE . self::LE .
				'<Required>' . self::LE .
				'  -s[server]           Target SSH server address/host' . self::LE .
				'  --root-dir=[dir]     Source root directory' . self::LE . self::LE .
				'<Optional>' . self::LE .
				'  -p[port]             Alternative SSH port number, default is 22' . self::LE .
				'  -u[username]         User for SSH login, if not given current shell username used' . self::LE .
				'  -v                   Increase verbosity' . self::LE .
				'  --diff-dir=[dir]     If file differences found target file(s) will be placed into this directory' . self::LE .
				'  --priv-key=[file]    Private key file location if not given [/home/username/.ssh/id_rsa] used' . self::LE .
				'  --pub-key=[file]     Public key file location if not given [/home/username/.ssh/id_rsa.pub] used' . self::LE
			);

			return false;
		}

		// check alternative SSH port if given
		$sshPort = 22;
		if (isset($optionList['p'])) {
			if (!preg_match('/^\d{2,5}$/',$optionList['p'])) {
				// invalid port
				$this->writeLine('Invalid SSH port number - ' . $optionList['p'],true);
				return false;
			}

			$sshPort = $optionList['p'] * 1;
		}

		// determine username for SSH login
		$username = (isset($optionList['u'])) ? $optionList['u'] : $_SERVER['USER'];

		// return options
		return [
			'serverAddr' => $optionList['s'],
			'rootDir' => rtrim($optionList['root-dir'],'/'),
			'serverPort' => $sshPort,
			'username' => $username,
			'verbose' => isset($optionList['v']),
			'diffDir' => (isset($optionList['diff-dir'])) ? rtrim($optionList['diff-dir'],'/') : false,
			'privateKey' => (isset($optionList['priv-key'])) ? $optionList['priv-key'] : '/home/' . $username . '/.ssh/id_rsa',
			'publicKey' => (isset($optionList['pub-key'])) ? $optionList['pub-key'] : '/home/' . $username . '/.ssh/id_rsa.pub'
		];
	}

	private function validatePaths(array $optionList) {

		// root dir
		if (!is_dir($optionList['rootDir'])) {
			$this->writeLine('Invalid root directory - ' . $optionList['rootDir'],true);
			return false;
		}

		// private key file
		if (!is_file($optionList['privateKey'])) {
			$this->writeLine('Unable to locate private key file - ' . $optionList['privateKey'],true);
			return false;
		}

		// public key file
		if (!is_file($optionList['publicKey'])) {
			$this->writeLine('Unable to locate public key file - ' . $optionList['publicKey'],true);
			return false;
		}

		// diff dir (only if given as option)
		$diffDir = $optionList['diffDir'];
		if (($diffDir !== false) && !is_dir($diffDir)) {
			// differences dir does not exist, attempt to create it
			if (!@mkdir($diffDir,0777,true)) {
				$this->writeLine('Unable to create differences directory - ' . $diffDir,true);
				return false;
			}
		}

		// all good
		return true;
	}

	private function sshConnect(array $optionList) {

		// make SSH connection
		if (($sshSession = @ssh2_connect(
			$optionList['serverAddr'],
			$optionList['serverPort']
		)) === false) {
			// connection error
			$this->writeLine(sprintf('Unable to connect to target server - %s:%d',$optionList['serverAddr'],$optionList['serverPort']),true);
			return false;
		}

		// make login using username/private/public key files
		if (!@ssh2_auth_pubkey_file(
			$sshSession,
			$optionList['username'],
			$optionList['publicKey'],
			$optionList['privateKey']
		)) {
			// auth error
			$this->writeLine('Unable to authenticate using given username/private key/public key',true);
			return false;
		}

		// connection was a success
		return $sshSession;
	}

	private function sshExec($sshSession,$cmd) {

		$stream = ssh2_exec($sshSession,$cmd);
		stream_set_blocking($stream,1);
		$data = stream_get_contents($stream);
		fclose($stream);

		return $data;
	}

	private function process(array $optionList,$sshSession) {

		return $this->workDir(
			$optionList['verbose'],
			$sshSession,
			$optionList['rootDir'],
			$optionList['diffDir']
		);
	}

	private function workDir($isVerbose,$sshSession,$baseDirLocal,$diffDir,$childDir = '',$differencesFound = false) {

		$dirHandle = @opendir($baseDirLocal . $childDir);
		if ($dirHandle === false) return $differencesFound;

		while (($fileItem = readdir($dirHandle)) !== false) {
			// skip current/parent directories
			if (($fileItem == '.') || ($fileItem == '..')) continue;

			// build local and remote file paths
			$fileItemLocal = $baseDirLocal . $childDir . '/' . $fileItem;
			$fileItemRemote = $childDir . '/' . $fileItem;

			if (is_dir($fileItemLocal)) {
				// file is a directory, call $this->workDir() recursively
				$differencesFound = $this->workDir(
					$isVerbose,
					$sshSession,
					$baseDirLocal,
					$diffDir,
					$childDir . '/' . $fileItem,
					$differencesFound
				);

				continue;
			}

			// process file local and remote
			$fileSHA1Local = sha1_file($fileItemLocal);
			if ($isVerbose) {
				$this->writeLine(sprintf('Checking: %s [%s]',$fileItemRemote,$fileSHA1Local));
			}

			$fileSHA1Remote = $this->parseSHA1shell(
				$this->sshExec($sshSession,sprintf(self::SHELL_SHA1_SPRINTF,$fileItemRemote))
			);

			// check local/remote file SHA1
			if ($fileSHA1Remote === false) {
				// remote file not found
				$this->writeLine('Remote file missing: ' . $fileItemRemote);
				$differencesFound = true;

			} elseif ($fileSHA1Local != $fileSHA1Remote) {
				// file differences found
				$this->writeLine('Difference found: ' . $fileItemRemote);
				$differencesFound = true;

				if ($diffDir !== false) {
					// diff directory defined, SCP file to local disk for offline comparing
					$targetDir = dirname($diffDir . $fileItemRemote);
					if (!is_dir($targetDir)) mkdir($targetDir,0777,true);
					ssh2_scp_recv($sshSession,$fileItemRemote,$diffDir . $fileItemRemote);

					if ($isVerbose) {
						$this->writeLine(sprintf('Transfered: %s => %s',$fileItemRemote,$diffDir . $fileItemRemote));
					}
				}
			}
		}

		// close directory handle
		closedir($dirHandle);
		return $differencesFound;
	}

	private function parseSHA1shell($data) {

		return (preg_match('/^([\da-f]{40})  /',$data,$match)) ? $match[1] : false;
	}

	private function writeLine($text = '',$isError = false) {

		echo((($isError) ? 'Error: ' : '') . $text . self::LE);
	}
}


new SSHDiff($argv);
