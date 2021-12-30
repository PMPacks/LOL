<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\protocol;

use function ceil;
use function chr;
use function ord;
use function strlen;
use function substr;

use pocketmine\utils\Binary;

class EncapsulatedPacket{
	private const RELIABILITY_SHIFT = 5;
	private const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	private const SPLIT_FLAG = 0b00010000;

	/** @var int */
	public $reliability;
	/** @var bool */
	public $hasSplit = false;
	/** @var int */
	public $length = 0;
	/** @var int|null */
	public $messageIndex;
	/** @var int|null */
	public $sequenceIndex;
	/** @var int|null */
	public $orderIndex;
	/** @var int|null */
	public $orderChannel;
	/** @var int|null */
	public $splitCount;
	/** @var int|null */
	public $splitID;
	/** @var int|null */
	public $splitIndex;
	/** @var string */
	public $buffer = "";
	/** @var bool */
	public $needACK = false;
	/** @var int|null */
	public $identifierACK;

	/**
	 * Decodes an EncapsulatedPacket from bytes generated by toInternalBinary().
	 *
	 * @param int|null $offset reference parameter, will be set to the number of bytes read
	 */
	public static function fromInternalBinary(string $bytes, ?int &$offset = null) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$offset = 0;
		$packet->reliability = ord($bytes[$offset++]);

		$length = (\unpack("N", substr($bytes, $offset, 4))[1] << 32 >> 32);
		$offset += 4;
		$packet->identifierACK = (\unpack("N", substr($bytes, $offset, 4))[1] << 32 >> 32); //TODO: don't read this for non-ack-receipt reliabilities
		$offset += 4;

		if(PacketReliability::isSequencedOrOrdered($packet->reliability)){
			$packet->orderChannel = ord($bytes[$offset++]);
		}

		$packet->buffer = substr($bytes, $offset, $length);
		$offset += $length;
		return $packet;
	}

	/**
	 * Encodes data needed for the EncapsulatedPacket to be transmitted from RakLib to the implementation's thread.
	 */
	public function toInternalBinary() : string{
		return
			chr($this->reliability) .
			(\pack("N", strlen($this->buffer))) .
			(\pack("N", $this->identifierACK ?? -1)) . //TODO: don't write this for non-ack-receipt reliabilities
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? chr($this->orderChannel) : "") .
			$this->buffer;
	}

	/**
	 * @param int    $offset reference parameter
	 */
	public static function fromBinary(string $binary, ?int &$offset = null) : EncapsulatedPacket{

		$packet = new EncapsulatedPacket();

		$flags = ord($binary[0]);
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$packet->hasSplit = $hasSplit = ($flags & self::SPLIT_FLAG) > 0;

		$length = (int) ceil((\unpack("n", substr($binary, 1, 2))[1]) / 8);
		$offset = 3;

		if(PacketReliability::isReliable($reliability)){
			$packet->messageIndex = (\unpack("V", substr($binary, $offset, 3) . "\x00")[1]);
			$offset += 3;
		}

		if(PacketReliability::isSequenced($reliability)){
			$packet->sequenceIndex = (\unpack("V", substr($binary, $offset, 3) . "\x00")[1]);
			$offset += 3;
		}

		if(PacketReliability::isSequencedOrOrdered($reliability)){
			$packet->orderIndex = (\unpack("V", substr($binary, $offset, 3) . "\x00")[1]);
			$offset += 3;
			$packet->orderChannel = ord($binary[$offset++]);
		}

		if($hasSplit){
			$packet->splitCount = (\unpack("N", substr($binary, $offset, 4))[1] << 32 >> 32);
			$offset += 4;
			$packet->splitID = (\unpack("n", substr($binary, $offset, 2))[1]);
			$offset += 2;
			$packet->splitIndex = (\unpack("N", substr($binary, $offset, 4))[1] << 32 >> 32);
			$offset += 4;
		}

		$packet->buffer = substr($binary, $offset, $length);
		$offset += $length;

		return $packet;
	}

	public function toBinary() : string{
		return
			chr(($this->reliability << self::RELIABILITY_SHIFT) | ($this->hasSplit ? self::SPLIT_FLAG : 0)) .
			(\pack("n", strlen($this->buffer) << 3)) .
			(PacketReliability::isReliable($this->reliability) ? (\substr(\pack("V", $this->messageIndex), 0, -1)) : "") .
			(PacketReliability::isSequenced($this->reliability) ? (\substr(\pack("V", $this->sequenceIndex), 0, -1)) : "") .
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? (\substr(\pack("V", $this->orderIndex), 0, -1)) . chr($this->orderChannel) : "") .
			($this->hasSplit ? (\pack("N", $this->splitCount)) . (\pack("n", $this->splitID)) . (\pack("N", $this->splitIndex)) : "")
			. $this->buffer;
	}

	public function getTotalLength() : int{
		return
			1 + //reliability
			2 + //length
			(PacketReliability::isReliable($this->reliability) ? 3 : 0) + //message index
			(PacketReliability::isSequenced($this->reliability) ? 3 : 0) + //sequence index
			(PacketReliability::isSequencedOrOrdered($this->reliability) ? 3 + 1 : 0) + //order index (3) + order channel (1)
			($this->hasSplit ? 4 + 2 + 4 : 0) + //split count (4) + split ID (2) + split index (4)
			strlen($this->buffer);
	}

	public function __toString() : string{
		return $this->toBinary();
	}
}
