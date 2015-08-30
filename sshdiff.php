#!/usr/bin/env php
<?php
class SSHDiff {

	const SHA1SUM_SHELL_SPRINTF = 'sha1sum "%1$s" 2>&1';
	const SHA1SUM_ERROR_NOT_FOUND = 1;
	const SHA1SUM_ERROR_PERMISSION_DENIED = 2;


	public function execute(array $argv) {

		// fetch command line options and validate given dirs - exit on error
		if (
			(($optionList = $this->getOptionList($argv)) === false) ||
			(!$this->validatePaths($optionList))
		) exit(1);

		// connect to target server via SSH - exit on error
		if (($sshSession = $this->sshConnect($optionList)) === false) exit(1);

		// start processing
		list($differenceFoundCount,$permissionIssueCount) = $this->process($optionList,$sshSession);

		// close SSH connection and write summary report
		$this->sshExec($sshSession,'logout');
		$this->writeSummary($differenceFoundCount,$permissionIssueCount);

		// exit with error code 2 (two) if differences found
		exit(($differenceFoundCount) ? 2 : 0);
	}

	private function getOptionList(array $argv) {

		$optionList = getopt('p:s:u:v',['diff-dir:','priv-key:','pub-key:','root-dir:']);

		// required options given?
		if (!isset($optionList['s'],$optionList['root-dir'])) {
			// no - display usage
			$scriptName = basename($argv[0]);

			$this->writeLine(<<<"EOT"
Usage:
  {$scriptName} -s SERVER --root-dir DIR

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
EOT
			);

			return false;
		}

		// check alternative SSH port if given
		$sshPort = 22;
		if (isset($optionList['p'])) {
			if (!preg_match('/^[0-9]{2,5}$/',$optionList['p'])) {
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

		// difference directory (only if given as option)
		$diffDir = $optionList['diffDir'];
		if (($diffDir !== false) && !is_dir($diffDir)) {
			// directory does not exist - attempt to create it
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

	private function workDir(
		$isVerbose,$sshSession,
		$baseDirLocal,$diffDir,$childDir = '',
		$differenceFoundCount = 0,$permissionIssueCount = 0
	) {

		if (!is_dir($baseDirLocal . $childDir)) {
			// invalid source directory
			return [$differenceFoundCount,$permissionIssueCount];
		}

		$dirHandle = opendir($baseDirLocal . $childDir);
		while (($fileItem = readdir($dirHandle)) !== false) {
			// skip current/parent directories
			if (($fileItem == '.') || ($fileItem == '..')) continue;

			// build local and remote file paths
			$fileItemLocal = $baseDirLocal . $childDir . '/' . $fileItem;
			$fileItemRemote = $childDir . '/' . $fileItem;

			if (is_dir($fileItemLocal)) {
				// file is a directory, call $this->workDir() recursively
				list($differenceFoundCount,$permissionIssueCount) = $this->workDir(
					$isVerbose,$sshSession,
					$baseDirLocal,$diffDir,$fileItemRemote,
					$differenceFoundCount,$permissionIssueCount
				);

				continue;
			}

			// process file local and remote
			$fileSHA1Local = sha1_file($fileItemLocal);
			if ($isVerbose) {
				$this->writeLine(sprintf('Checking: %s [%s]',$fileItemRemote,$fileSHA1Local));
			}

			$fileSHA1Remote = $this->parseSHA1SumShell(
				$this->sshExec($sshSession,sprintf(
					self::SHA1SUM_SHELL_SPRINTF,
					$this->escapeFilePath($fileItemRemote)
				))
			);

			// check local/remote file SHA1
			if ($fileSHA1Remote === self::SHA1SUM_ERROR_PERMISSION_DENIED) {
				// unable to access remote file due to permissions
				$this->writeLine('Permission denied: ' . $fileItemRemote);
				$permissionIssueCount++;

			} elseif ($fileSHA1Remote === self::SHA1SUM_ERROR_NOT_FOUND) {
				// remote file not found
				$this->writeLine('Missing: ' . $fileItemRemote);
				$differenceFoundCount++;

			} elseif ($fileSHA1Local != $fileSHA1Remote) {
				// file difference(s) found
				$this->writeLine('Difference: ' . $fileItemRemote);
				$differenceFoundCount++;

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

		// return number of file differences found and permission issues (unable to read server file)
		return [$differenceFoundCount,$permissionIssueCount];
	}

	private function writeSummary($differenceFoundCount,$permissionIssueCount) {

		$summaryText = 'All done - ' . (($differenceFoundCount) ? $differenceFoundCount . ' difference(s) found' : 'no differences');

		if ($permissionIssueCount > 0) {
			// issues found checking server files - report total count
			$summaryText .= ', unable to check ' . $permissionIssueCount . ' file(s) due to permissions';
		}

		$this->writeLine(
			"\n" . str_repeat('=',strlen($summaryText)) . "\n" .
			$summaryText
		);
	}

	private function parseSHA1SumShell($data) {

		if (preg_match('/^([0-9a-f]{40})  /',$data,$match)) {
			// generated SHA1 hash from source file
			return $match[1];
		}

		if (preg_match('/: Permission denied$/',$data)) {
			// unable to access file (or a path therein) due to permissions
			return self::SHA1SUM_ERROR_PERMISSION_DENIED;
		}

		// assume file not found
		return self::SHA1SUM_ERROR_NOT_FOUND;
	}

	private function escapeFilePath($path) {

		return str_replace('"','\"',$path);
	}

	private function writeLine($text = '',$isError = false) {

		echo((($isError) ? 'Error: ' : '') . $text . "\n");
	}
}


(new SSHDiff())->execute($argv);
