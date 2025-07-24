<?php

namespace martyd420\seznam_fastrpc;

use Exception;

class SeznamFastRPC
{
	private const TYPE_CALL      = 13;
	private const TYPE_RESPONSE  = 14;
	private const TYPE_FAULT     = 15;

	private const TYPE_INT       = 1;
	private const TYPE_BOOL      = 2;
	private const TYPE_DOUBLE    = 3;
	private const TYPE_STRING    = 4;
	private const TYPE_DATETIME  = 5;
	private const TYPE_BINARY    = 6;
	private const TYPE_INT8P     = 7;
	private const TYPE_INT8N     = 8;
	private const TYPE_STRUCT    = 10;
	private const TYPE_ARRAY     = 11;
	private const TYPE_NULL      = 12;

	private array $_data = [];
	private array $_path = [];
	private mixed $_hints = null;
	private int $_pointer = 1;
	private ?float $_version = null;
	private bool $_arrayBuffers = false;

	public function encodeMessage($method, $data, $hints): string
	{
		$c = $this->serializeCall($method, $data, $hints);

		$binaryData = pack('C*', ...$c);
		$binaryData = base64_encode($binaryData);
		return $binaryData;
	}

	function serializeCall($method, $data, $hints): array
	{
		$result = $this->serialize($data, $hints);

		/* utrhnout hlavicku pole (dva bajty) */
		array_shift($result);
		array_shift($result);

		$encodedMethod = $this->_encodeUTF8($method);
		array_unshift($result, ...$encodedMethod);
		array_unshift($result, count($encodedMethod));

		array_unshift($result, self::TYPE_CALL << 3);
		array_unshift($result, 0xCA, 0x11, 0x02, 0x01);

		return $result;
	}

	function serialize($data, $hints): array
	{
		$result = [];
		$this->_path = [];
		$this->_hints = $hints;
		$this->_serializeValue($result, $data);
		$this->_hints = null;
		return $result;
	}

	function _append(&$arr1, $arr2): void
	{
		foreach ($arr2 as $item) {
			$arr1[] = $item;
		}
	}

	function _encodeInt($data): array
	{
		if (!$data) {
			return [0];
		}

		$result = [];
		$remain = $data;

		while ($remain) {
			$value = $remain % 256;
			$remain = ($remain - $value) / 256;
			$result[] = $value;
		}

		return $result;
	}

	function _serializeValue(&$result, $value): void
	{
		if ($value === null) {
			$result[] = self::TYPE_NULL << 3;
			return;
		}


		switch (gettype($value)) {
			case "string":
				$strData = $this->_encodeUTF8($value);
				$intData = $this->_encodeInt(count($strData));

				$first = self::TYPE_STRING << 3;
				$first += (count($intData) - 1);

				$result[] = $first;
				$this->_append($result, $intData);
				$this->_append($result, $strData);
				break;

			case "boolean":
				$data = self::TYPE_BOOL << 3;
				if ($value) {
					$data += 1;
				}
				$result[] = $data;
				break;

			case "array":
				if (isset($value[0])) {
					$this->_serializeArray($result, $value);
				} else {
					$this->_serializeStruct($result, $value);
				}
				break;

			case "object":
				if ($value instanceof \DateTime) {
					$this->_serializeDate($result, $value);
				} else {
					$this->_serializeStruct($result, (array)$value);
				}
				break;

			case "integer":
			case "float":
			case "double":
				// _hints nefunguje, pokud cislo obsahuje tecku, je to float
				if ($this->_getHint() == "float" || str_contains((string)$value, '.')) { /* float */
					$value = (float)$value;
					$first = self::TYPE_DOUBLE << 3;
					$floatData = $this->_encodeDouble($value);

					$result[] = $first;
					$this->_append($result, $floatData);
				} else { /* int */
					$value = (int)$value;
					$first = ($value >= 0) ? self::TYPE_INT8P : self::TYPE_INT8N;
					$first = $first << 3;

					$data = $this->_encodeInt(abs($value));
					$first += (count($data) - 1);

					$result[] = $first;
					$this->_append($result, $data);
				}
				break;

			default: /* undefined, function, ... */
				throw new Exception("FRPC does not allow value " . gettype($value) . ' ' . $value);
		}
	}

	private function _serializeDate(&$result, $date): void
	{
		$result[] = self::TYPE_DATETIME << 3;

		/* 1 bajt, zona */
		$zone = $date->getOffset() / 900; /* pocet ctvrthodin */
		if ($zone < 0) {
			$zone += 256; /* dvojkovy doplnek */
		}
		$result[] = $zone;

		/* 4 bajty, timestamp */
		$ts = round($date->getTimestamp());
		if ($ts < 0 || $ts >= pow(2, 31)) {
			$ts = -1;
		}
		if ($ts < 0) {
			$ts += pow(2, 32); /* dvojkovy doplnek */
		}
		$tsData = $this->_encodeInt($ts);
		while (count($tsData) < 4) {
			$tsData[] = 0;
		} /* do 4 bajtu */
		$this->_append($result, $tsData);

		/* 5 bajtu, zbyle haluze */
		$year = $date->format('Y') - 1600;
		$year = max($year, 0);
		$year = min($year, 2047);
		$month = $date->format('n');
		$day = $date->format('j');
		$dow = $date->format('w');
		$hours = $date->format('G');
		$minutes = $date->format('i');
		$seconds = $date->format('s');

		$result[] = (($seconds & 0x1f) << 3) | ($dow & 0x07);
		$result[] = (($minutes & 0x3f) << 1) | (($seconds & 0x20) >> 5) | (($hours & 0x01) << 7);
		$result[] = (($hours & 0x1e) >> 1) | (($day & 0x0f) << 4);
		$result[] = (($day & 0x1f) >> 4) | (($month & 0x0f) << 1) | (($year & 0x07) << 5);
		$result[] = ($year & 0x07f8) >> 3;
	}

	function _serializeArray(&$result, $data): void
	{
		if ($this->_getHint() == "binary") { /* binarni data */
			$first = self::TYPE_BINARY << 3;
			$intData = $this->_encodeInt(count($data));
			$first += (count($intData) - 1);

			$result[] = $first;
			$this->_append($result, $intData);
			$this->_append($result, $data);
			return;
		}

		$first = self::TYPE_ARRAY << 3;
		$intData = $this->_encodeInt(count($data));
		$first += (count($intData) - 1);

		$result[] = $first;
		$this->_append($result, $intData);

		foreach ($data as $i => $value) {
			$this->_path[] = $i;
			$this->_serializeValue($result, $value);
			array_pop($this->_path);
		}
	}


	function _serializeStruct(&$result, $data): void
	{
		$numMembers = 0;
		foreach ($data as $p => $v) {
			$numMembers++;
		}

		$first = self::TYPE_STRUCT << 3;
		$intData = $this->_encodeInt($numMembers);
		$first += (count($intData) - 1);

		$result[] = $first;
		$this->_append($result, $intData);

		foreach ($data as $p => $v) {
			$strData = $this->_encodeUTF8($p);
			$result[] = count($strData);
			$this->_append($result, $strData);
			$this->_path[] = $p;
			$this->_serializeValue($result, $v);
			array_pop($this->_path);
		}
	}


	function _encodeDouble($num): array
	{
		$result = [];

		$expBits = 11;
		$fracBits = 52;
		$bias = (1 << ($expBits - 1)) - 1;

		$sign = 0;
		$exponent = 0;
		$fraction = 0;

		if (is_nan($num)) {
			$exponent = (1 << $expBits) - 1;
			$fraction = 1;
		} elseif (is_infinite($num)) {
			$exponent = (1 << $expBits) - 1;
			$fraction = 0;
			$sign = ($num < 0) ? 1 : 0;
		} elseif ($num == 0) {
			$exponent = 0;
			$fraction = 0;
			$sign = (1 / $num === -INF) ? 1 : 0;
		} else { /* normal number */
			$sign = ($num < 0) ? 1 : 0;
			$abs = abs($num);

			if ($abs >= pow(2, 1 - $bias)) {
				$ln = min(floor(log($abs) / log(2)), $bias);
				$exponent = $ln + $bias;
				$fraction = $abs * pow(2, $fracBits - $ln) - pow(2, $fracBits);
			} else {
				$exponent = 0;
				$fraction = $abs / pow(2, 1 - $bias - $fracBits);
			}
		}

		$bits = [];
		for ($i = $fracBits; $i > 0; $i--) {
			$bits[] = ($fraction % 2) ? 1 : 0;
			$fraction = floor($fraction / 2);
		}

		for ($i = $expBits; $i > 0; $i--) {
			$bits[] = ($exponent % 2) ? 1 : 0;
			$exponent = floor($exponent / 2);
		}
		$bits[] = $sign ? 1 : 0;

		$num = 0;
		$index = 0;
		while (count($bits)) {
			$num += (1 << $index) * array_shift($bits);
			$index++;
			if ($index == 8) {
				$result[] = $num;
				$num = 0;
				$index = 0;
			}
		}
		return $result;
	}


	function _getHint() {
		if (!isset($this->_hints)) {
			return null;
		}
		if (!is_array($this->_hints)) {
			return $this->_hints; /* skalarni varianta */
		}
		return $this->_hints[implode('.', $this->_path)] ?? null;
	}

	function _encodeUTF8($str): array
	{
		$result = [];
		$length = mb_strlen($str, 'UTF-8');
		for ($i = 0; $i < $length; $i++) {
			$c = mb_ord(mb_substr($str, $i, 1, 'UTF-8'));
			if ($c >= 55296 && $c <= 56319) { /* surrogates */
				$c2 = mb_ord(mb_substr($str, ++$i, 1, 'UTF-8'));
				$c = (($c & 0x3FF) << 10) + ($c2 & 0x3FF) + 0x10000;
			}

			if ($c < 128) {
				$result[] = $c;
			} elseif ($c < 2048) {
				$result[] = ($c >> 6) | 192;
				$result[] = ($c & 63) | 128;
			} elseif ($c < 65536) {
				$result[] = ($c >> 12) | 224;
				$result[] = (($c >> 6) & 63) | 128;
				$result[] = ($c & 63) | 128;
			} else {
				$result[] = ($c >> 18) | 240;
				$result[] = (($c >> 12) & 63) | 128;
				$result[] = (($c >> 6) & 63) | 128;
				$result[] = ($c & 63) | 128;
			}
		}
		return $result;
	}

	public function decodeMessage($b64_encoded_data)
	{
		$binary_data = base64_decode($b64_encoded_data);
		$this->_data = unpack('C*', $binary_data);

		// Check for magic bytes
		$m1 = $this->_getByte();
		$m2 = $this->_getByte();
		if ($m1 != 0xCA || $m2 != 0x11) {
			throw new Exception("Invalid FRPC message: Missing magic bytes");
		}

		// version bytes
		$v1 = $this->_getByte();
		$v2 = $this->_getByte();
		$this->_version = (float)($v1 .'.'. $v2);


		$first = $this->_getInt(1);
		$type = ($first >> 3);

		if ($type == self::TYPE_FAULT) die(' --- TYPE_FAULT --- ');

		if ($type === self::TYPE_RESPONSE) {
			$result = $this->_parseValue();
		} elseif ($type === self::TYPE_CALL) {
			$nameLength = $this->_getInt(1);
			$name = $this->_decodeUTF8($nameLength);
			$params = [];
			while ($this->_pointer < count($this->_data)) {
				$params[] = $this->_parseValue();
			}

			$result = [
				'name' => $name,
				'params' => $params,
			];

		} else {
			$result = false;
		}

		$this->_pointer = 1;
		$this->_data = [];

		return $result;
	}

	private	function _parseValue(): float|array|bool|int|string|null
	{
		$first = $this->_getInt(1);
		$type = $first >> 3;

		switch ($type) {
			case self::TYPE_STRING:
				$lengthBytes = ($first & 7);
				if ($this->_version > 1) { $lengthBytes++; }
				if (!$lengthBytes) { throw new Exception("Bad string size"); }
				$length = $this->_getInt($lengthBytes);
				return $this->_decodeUTF8($length);

			case self::TYPE_STRUCT:
				$result = [];
				$lengthBytes = ($first & 7);
				if ($this->_version > 1) { $lengthBytes++;}
				$members = $this->_getInt($lengthBytes);
				while ($members--) { $this->_parseMember($result); }
				return $result;

			case self::TYPE_ARRAY:
				$result = [];
				$lengthBytes = ($first & 7);
				if ($this->_version > 1) { $lengthBytes++; }
				$members = $this->_getInt($lengthBytes);
				while ($members--) { $result[] = $this->_parseValue(); }
				return $result;

			case self::TYPE_BOOL:
				$result = ($first & 7);
				if ($result > 1) { throw new Exception("Invalid bool value $result"); }
				return (bool)$result;

			case self::TYPE_INT:
				$length = $first & 7;
				if ($this->_version == 3) {
					return $this->_getZigzag($length + 1);
				} else {
					if (!$length) { throw new Exception("Bad int size"); }
					$max = 0x80000000;
					$result = $this->_getInt($length);
					if ($result >= $max) { $result -= $max; }
					return $result;
				}

			case self::TYPE_DATETIME:
				$this->_getByte();
				$tsBytes = ($this->_version == 3) ? 8 : 4;
				$ts = $this->_getInt($tsBytes);
				for ($i = 0; $i < 5; $i++) { $this->_getByte(); }
				return date('Y-m-d H:i:s', $ts);

			case self::TYPE_DOUBLE:
				return $this->_getDouble();

			case self::TYPE_BINARY:
				$lengthBytes = ($first & 7);
				if ($this->_version > 1) { $lengthBytes++; }
				if (!$lengthBytes) { throw new Exception("Bad binary size"); }
				$length = $this->_getInt($lengthBytes);
				$result = [];
				if ($this->_arrayBuffers) {
					for ($i = 0; $i < $length; $i++) {
						$result[] = $this->_getByte();
					}
				} else {
					while ($length--) { $result[] = $this->_getByte(); }
				}
				return $result;

			case self::TYPE_INT8P:
				$length = ($first & 7) + 1;
				return $this->_getInt($length);

			case self::TYPE_INT8N:
				$length = ($first & 7) + 1;
				$result = $this->_getInt($length);
				return ($result ? -$result : 0); // no negative zero

			case self::TYPE_NULL:
				if ($this->_version > 1) {
					return null;
				} else {
					throw new Exception("Null value not supported in protocol v1");
				}

			default:
				throw new Exception("Unknown type $type");
		}
	}


	private function _getDouble(): float|int
	{
		$bytes = [];
		$index = 8;

		while ($index--) {
			$bytes[$index] = $this->_getByte();
		}

		$sign = ($bytes[0] & 0x80) ? 1 : 0;

		// Získání exponentu
		$exponent = ($bytes[0] & 127) << 4;
		$exponent += $bytes[1] >> 4;

		// Pokud je exponent nula, vrátíme nulu
		if ($exponent === 0) {
			return pow(-1, $sign) * 0;
		}

		// Získání mantissy
		$mantissa = 0;
		$byteIndex = 1;
		$bitIndex = 3;
		$index = 1;

		do {
			$bitValue = ($bytes[$byteIndex] & (1 << $bitIndex)) ? 1 : 0;
			$mantissa += $bitValue * pow(2, -$index);

			$index++;
			$bitIndex--;
			if ($bitIndex < 0) {
				$bitIndex = 7;
				$byteIndex++;
			}
		} while ($byteIndex < count($bytes));

		// Kontrola na speciální hodnoty (NaN, Infinity)
		if ($exponent == 0x7ff) {
			if ($mantissa) {
				return NAN;
			} else {
				return pow(-1, $sign) * INF;
			}
		}

		// Normalizovaný výsledek
		$exponent -= (1 << 10) - 1;
		return pow(-1, $sign) * pow(2, $exponent) * (1 + $mantissa);
	}

	private function _decodeUTF8($length): string
	{
		$ab = [];
		for ($i = 0; $i < $length; $i++) {
			$ab[$i] = $this->_data[$this->_pointer++];
		}

		return implode(array_map('chr', $ab));
	}

	private function _parseMember(&$result): void
	{
		$nameLength = $this->_getInt(1);
		$name = $this->_decodeUTF8($nameLength);
		$result[$name] = $this->_parseValue();
	}

	private function _getZigzag($bytes): float
	{
		$result = $this->_getInt($bytes);
		$minus = $result % 2;
		$result = floor($result / 2);

		return ($minus ? -1 * ($result + 1) : $result);
	}

	private function _getInt($bytes): int
	{
		$result = 0;
		$factor = 1;

		for ($i = 0; $i < $bytes; $i++) {
			$result += $factor * $this->_getByte();
			$factor *= 256;
		}

		return $result;
	}

	private function _getByte() {
		if (($this->_pointer) > count($this->_data)) {
			throw new Exception("Cannot read byte $this->_pointer from buffer");
		}
		$byte = $this->_data[$this->_pointer++];

		return $byte;
	}

}
