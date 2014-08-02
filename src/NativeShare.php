<?php
/**
 * Copyright (c) 2014 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Licensed under the MIT license:
 * http://opensource.org/licenses/MIT
 */

namespace Icewind\SMB;

require_once 'ErrorCodes.php';

class NativeShare implements IShare {
	/**
	 * @var Server $server
	 */
	private $server;

	/**
	 * @var string $name
	 */
	private $name;

	/**
	 * @var \Icewind\SMB\NativeState $state
	 */
	private $state;

	/**
	 * @param Server $server
	 * @param string $name
	 */
	public function __construct($server, $name) {
		$this->server = $server;
		$this->name = $name;
		$this->state = new NativeState();
	}

	/**
	 * @throws \Icewind\SMB\ConnectionError
	 * @throws \Icewind\SMB\AuthenticationException
	 * @throws \Icewind\SMB\InvalidHostException
	 */
	protected function connect() {
		if ($this->state and is_resource($this->state)) {
			return;
		}

		$user = $this->server->getUser();
		$workgroup = null;
		if (strpos($user, '/')) {
			list($workgroup, $user) = explode($user, '/');
		}
		$this->state->init($workgroup, $user, $this->server->getPassword());
	}

	/**
	 * Get the name of the share
	 *
	 * @return string
	 */
	public function getName() {
		return $this->name;
	}

	private function buildUrl($path) {
		$url = 'smb://' . $this->server->getHost() . '/' . $this->name;
		if ($path) {
			$path = trim($path, '/');
			$url .= '/' . $path;
		}
		return $url;
	}

	/**
	 * List the content of a remote folder
	 *
	 * @param string $path
	 * @return \Icewind\SMB\IFileInfo[]
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function dir($path) {
		$this->connect();
		$files = array();

		$dh = $this->state->opendir($this->buildUrl($path));
		while ($file = $this->state->readdir($dh)) {
			$name = $file['name'];
			if ($name !== '.' and $name !== '..') {
				$files [] = new NativeFileInfo($this, $path . '/' . $name, $name);
			}
		}

		$this->state->closedir($dh);
		return $files;
	}

	public function stat($path) {
		$this->connect();
		return $this->state->stat($this->buildUrl($path));
	}

	/**
	 * Create a folder on the share
	 *
	 * @param string $path
	 * @return bool
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\AlreadyExistsException
	 */
	public function mkdir($path) {
		$this->connect();
		return $this->state->mkdir($this->buildUrl($path));
	}

	/**
	 * Remove a folder on the share
	 *
	 * @param string $path
	 * @return bool
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function rmdir($path) {
		$this->connect();
		return $this->state->rmdir($this->buildUrl($path));
	}

	/**
	 * Delete a file on the share
	 *
	 * @param string $path
	 * @return bool
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function del($path) {
		return $this->state->unlink($this->buildUrl($path));
	}

	/**
	 * Rename a remote file
	 *
	 * @param string $from
	 * @param string $to
	 * @return bool
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\AlreadyExistsException
	 */
	public function rename($from, $to) {
		$this->connect();
		return $this->state->rename($this->buildUrl($from), $this->buildUrl($to));
	}

	/**
	 * Upload a local file
	 *
	 * @param string $source local file
	 * @param string $target remove file
	 * @return bool
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function put($source, $target) {
		$this->connect();
		$sourceHandle = fopen($source, 'rb');
		$targetHandle = $this->state->create($this->buildUrl($target));

		while ($data = fread($sourceHandle, 4096)) {
			$this->state->write($targetHandle, $data);
		}
		$this->state->close($targetHandle);
		restore_error_handler();
		return true;
	}

	/**
	 * Download a remote file
	 *
	 * @param string $source remove file
	 * @param string $target local file
	 * @return bool
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function get($source, $target) {
		$this->connect();
		$sourceHandle = $this->state->open($this->buildUrl($source), 'r');
		$targetHandle = fopen($target, 'wb');

		while ($data = $this->state->read($sourceHandle, 4096)) {
			fwrite($targetHandle, $data);
		}
		$this->state->close($sourceHandle);
		return true;
	}

	/**
	 * Open a readable stream top a remote file
	 *
	 * @param string $source
	 * @return resource a read only stream with the contents of the remote file
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function read($source) {
		$this->connect();
		$handle = $this->state->open($this->buildUrl($source), 'r');
		return NativeStream::wrap($this->state->getState(), $handle, 'r');
	}

	/**
	 * Open a readable stream top a remote file
	 *
	 * @param string $source
	 * @return resource a read only stream with the contents of the remote file
	 *
	 * @throws \Icewind\SMB\NotFoundException
	 * @throws \Icewind\SMB\InvalidTypeException
	 */
	public function write($source) {
		$this->connect();
		$handle = $this->state->create($this->buildUrl($source));
		return NativeStream::wrap($this->state->getState(), $handle, 'w');
	}

	/**
	 * Get extended attributes for the path
	 *
	 * @param string $path
	 * @param string $attribute attribute to get the info
	 * @return string the attribute value
	 */
	public function getAttribute($path, $attribute) {
		$this->connect();

		$result = $this->state->getxattr($this->buildUrl($path), $attribute);
		// parse hex string
		if ($attribute === 'system.dos_attr.mode') {
			$result = hexdec(substr($result, 2));
		}
		return $result;
	}

	public function __destruct() {
		unset($this->state);
	}
}
