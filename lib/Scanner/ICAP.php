<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2020 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Files_Antivirus\Scanner;

use OCA\Files_Antivirus\AppConfig;
use OCA\Files_Antivirus\ICAP\ICAPClient;
use OCA\Files_Antivirus\ICAP\ICAPRequest;
use OCA\Files_Antivirus\Status;
use OCA\Files_Antivirus\StatusFactory;
use OCP\ILogger;

class ICAP extends ScannerBase {
	/** @var ICAPClient */
	private $icapClient;
	/** @var ?ICAPRequest */
	private $request;
	/** @var string */
	private $service;
	/** @var string */
	private $virusHeader;
	/** @var int */
	private $chunkSize;

	public function __construct(
		AppConfig $config,
		ILogger $logger,
		StatusFactory $statusFactory
	) {
		parent::__construct($config, $logger, $statusFactory);

		$avHost = $this->appConfig->getAvHost();
		$avPort = $this->appConfig->getAvPort();
		$this->service = $config->getAvIcapRequestService();
		$this->virusHeader = $config->getAvIcapResponseHeader();
		$this->chunkSize = (int)$config->getAvIcapChunkSize();

		if (!($avHost && $avPort)) {
			throw new \RuntimeException('The ICAP port and host are not set up.');
		}
		$this->icapClient = new ICAPClient($avHost, (int)$avPort, (int)$config->getAvIcapConnectTimeout());
	}

	public function initScanner() {
		parent::initScanner();
		$this->writeHandle = fopen("php://temp", 'w+');
		$this->request = $this->icapClient->reqmod($this->service, [
			'Allow' => 204,
		], [
			"PUT / HTTP/1.0",
			"Host: 127.0.0.1"
		]);
	}

	protected function writeChunk($chunk) {
		if (ftell($this->writeHandle) > $this->chunkSize) {
			$this->flushBuffer();
		}
		parent::writeChunk($chunk);
	}

	private function flushBuffer() {
		rewind($this->writeHandle);
		$data = stream_get_contents($this->writeHandle);
		$this->request->write($data);
		$this->writeHandle = fopen("php://temp", 'w+');
	}

	protected function scanBuffer() {
		$this->flushBuffer();
		$response = $this->request->finish();
		$code = $response->getStatus()->getCode();

		$this->status->setNumericStatus(Status::SCANRESULT_CLEAN);
		if ($code === 200 || $code === 204) {
			// c-icap/clamav reports this header
			$virus = $response->getIcapHeaders()[$this->virusHeader] ?? false;
			if ($virus) {
				$this->status->setNumericStatus(Status::SCANRESULT_INFECTED);
				$this->status->setDetails($virus);
			}

			// kaspersky(pre 2020 product editions) and McAfee handling
			$respHeader = $response->getResponseHeaders()['HTTP_STATUS'] ?? '';
			if (\strpos($respHeader, '403 Forbidden') || \strpos($respHeader, '403 VirusFound')) {
				$this->status->setNumericStatus(Status::SCANRESULT_INFECTED);
			}
		} else {
			throw new \RuntimeException('Invalid response from ICAP server');
		}
	}

	protected function shutdownScanner() {
		$this->scanBuffer();
	}
}
