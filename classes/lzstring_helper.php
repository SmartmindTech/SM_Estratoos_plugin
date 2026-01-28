<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * LZ-String compression/decompression for PHP.
 *
 * Port of the JavaScript LZ-String library for handling Articulate Storyline
 * suspend_data which uses LZ compression with Base64 encoding.
 *
 * @package    local_sm_estratoos_plugin
 * @copyright  2025 SmartMind Technologies
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_sm_estratoos_plugin;

defined('MOODLE_INTERNAL') || die();

/**
 * LZ-String helper class for compression/decompression.
 */
class lzstring_helper {

    /** @var string Base64 key string */
    private static $keyStrBase64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=";

    /** @var array|null Base64 reverse dictionary */
    private static $baseReverseDic = null;

    /**
     * Get character code at position.
     *
     * @param string $str String
     * @param int $index Position
     * @return int Character code
     */
    private static function charCodeAt(string $str, int $index): int {
        return mb_ord(mb_substr($str, $index, 1, 'UTF-8'), 'UTF-8');
    }

    /**
     * Get character from code.
     *
     * @param int $code Character code
     * @return string Character
     */
    private static function fromCharCode(int $code): string {
        return mb_chr($code, 'UTF-8');
    }

    /**
     * Get reverse dictionary for Base64.
     *
     * @return array Reverse dictionary
     */
    private static function getBaseReverseDic(): array {
        if (self::$baseReverseDic === null) {
            self::$baseReverseDic = [];
            for ($i = 0; $i < strlen(self::$keyStrBase64); $i++) {
                self::$baseReverseDic[self::$keyStrBase64[$i]] = $i;
            }
        }
        return self::$baseReverseDic;
    }

    /**
     * Decompress from Base64.
     *
     * @param string $input Compressed Base64 string
     * @return string|null Decompressed string or null on failure
     */
    public static function decompressFromBase64(?string $input): ?string {
        if ($input === null || $input === "") {
            return "";
        }

        $reverseDic = self::getBaseReverseDic();
        $inputLength = strlen($input);

        return self::decompress($inputLength, 32, function($index) use ($input, $reverseDic) {
            if ($index >= strlen($input)) {
                return 0;
            }
            $char = $input[$index];
            return $reverseDic[$char] ?? 0;
        });
    }

    /**
     * Core decompression algorithm.
     *
     * @param int $length Input length
     * @param int $resetValue Reset value for bit reading
     * @param callable $getNextValue Function to get next value
     * @return string|null Decompressed string
     */
    private static function decompress(int $length, int $resetValue, callable $getNextValue): ?string {
        $dictionary = [];
        $enlargeIn = 4;
        $dictSize = 4;
        $numBits = 3;
        $entry = "";
        $result = [];
        $w = "";
        $bits = 0;
        $resb = 0;
        $maxpower = 0;
        $power = 0;
        $c = "";

        $data_val = $getNextValue(0);
        $data_position = $resetValue;
        $data_index = 1;

        for ($i = 0; $i < 3; $i++) {
            $dictionary[$i] = $i;
        }

        $maxpower = pow(2, 2);
        $power = 1;
        while ($power != $maxpower) {
            $resb = $data_val & $data_position;
            $data_position >>= 1;
            if ($data_position == 0) {
                $data_position = $resetValue;
                $data_val = $getNextValue($data_index++);
            }
            $bits |= ($resb > 0 ? 1 : 0) * $power;
            $power <<= 1;
        }

        switch ($bits) {
            case 0:
                $bits = 0;
                $maxpower = pow(2, 8);
                $power = 1;
                while ($power != $maxpower) {
                    $resb = $data_val & $data_position;
                    $data_position >>= 1;
                    if ($data_position == 0) {
                        $data_position = $resetValue;
                        $data_val = $getNextValue($data_index++);
                    }
                    $bits |= ($resb > 0 ? 1 : 0) * $power;
                    $power <<= 1;
                }
                $c = self::fromCharCode($bits);
                break;
            case 1:
                $bits = 0;
                $maxpower = pow(2, 16);
                $power = 1;
                while ($power != $maxpower) {
                    $resb = $data_val & $data_position;
                    $data_position >>= 1;
                    if ($data_position == 0) {
                        $data_position = $resetValue;
                        $data_val = $getNextValue($data_index++);
                    }
                    $bits |= ($resb > 0 ? 1 : 0) * $power;
                    $power <<= 1;
                }
                $c = self::fromCharCode($bits);
                break;
            case 2:
                return "";
        }

        $dictionary[3] = $c;
        $w = $c;
        $result[] = $c;

        while (true) {
            if ($data_index > $length) {
                return "";
            }

            $bits = 0;
            $maxpower = pow(2, $numBits);
            $power = 1;
            while ($power != $maxpower) {
                $resb = $data_val & $data_position;
                $data_position >>= 1;
                if ($data_position == 0) {
                    $data_position = $resetValue;
                    $data_val = $getNextValue($data_index++);
                }
                $bits |= ($resb > 0 ? 1 : 0) * $power;
                $power <<= 1;
            }

            $c_code = $bits;
            switch ($c_code) {
                case 0:
                    $bits = 0;
                    $maxpower = pow(2, 8);
                    $power = 1;
                    while ($power != $maxpower) {
                        $resb = $data_val & $data_position;
                        $data_position >>= 1;
                        if ($data_position == 0) {
                            $data_position = $resetValue;
                            $data_val = $getNextValue($data_index++);
                        }
                        $bits |= ($resb > 0 ? 1 : 0) * $power;
                        $power <<= 1;
                    }
                    $dictionary[$dictSize++] = self::fromCharCode($bits);
                    $c_code = $dictSize - 1;
                    $enlargeIn--;
                    break;
                case 1:
                    $bits = 0;
                    $maxpower = pow(2, 16);
                    $power = 1;
                    while ($power != $maxpower) {
                        $resb = $data_val & $data_position;
                        $data_position >>= 1;
                        if ($data_position == 0) {
                            $data_position = $resetValue;
                            $data_val = $getNextValue($data_index++);
                        }
                        $bits |= ($resb > 0 ? 1 : 0) * $power;
                        $power <<= 1;
                    }
                    $dictionary[$dictSize++] = self::fromCharCode($bits);
                    $c_code = $dictSize - 1;
                    $enlargeIn--;
                    break;
                case 2:
                    return implode('', $result);
            }

            if ($enlargeIn == 0) {
                $enlargeIn = pow(2, $numBits);
                $numBits++;
            }

            if (isset($dictionary[$c_code])) {
                $entry = $dictionary[$c_code];
            } else {
                if ($c_code === $dictSize) {
                    $entry = $w . mb_substr($w, 0, 1, 'UTF-8');
                } else {
                    return null;
                }
            }
            $result[] = $entry;

            // Add w+entry[0] to the dictionary.
            $dictionary[$dictSize++] = $w . mb_substr($entry, 0, 1, 'UTF-8');
            $enlargeIn--;

            $w = $entry;

            if ($enlargeIn == 0) {
                $enlargeIn = pow(2, $numBits);
                $numBits++;
            }
        }
    }

    /**
     * Compress to Base64.
     *
     * @param string|null $input Input string
     * @return string|null Compressed Base64 string
     */
    public static function compressToBase64(?string $input): ?string {
        if ($input === null) {
            return "";
        }

        $res = self::compress($input, 6, function($a) {
            return self::$keyStrBase64[$a];
        });

        // Pad to make length multiple of 4
        switch (strlen($res) % 4) {
            case 0:
                return $res;
            case 1:
                return $res . "===";
            case 2:
                return $res . "==";
            case 3:
                return $res . "=";
        }
        return $res;
    }

    /**
     * Core compression algorithm.
     *
     * @param string $uncompressed Input string
     * @param int $bitsPerChar Bits per character
     * @param callable $getCharFromInt Function to get character from int
     * @return string Compressed string
     */
    private static function compress(string $uncompressed, int $bitsPerChar, callable $getCharFromInt): string {
        if ($uncompressed === "") {
            return "";
        }

        $context_dictionary = [];
        $context_dictionaryToCreate = [];
        $context_c = "";
        $context_wc = "";
        $context_w = "";
        $context_enlargeIn = 2;
        $context_dictSize = 3;
        $context_numBits = 2;
        $context_data = [];
        $context_data_val = 0;
        $context_data_position = 0;

        $uncompressedLength = mb_strlen($uncompressed, 'UTF-8');

        for ($ii = 0; $ii < $uncompressedLength; $ii++) {
            $context_c = mb_substr($uncompressed, $ii, 1, 'UTF-8');

            if (!isset($context_dictionary[$context_c])) {
                $context_dictionary[$context_c] = $context_dictSize++;
                $context_dictionaryToCreate[$context_c] = true;
            }

            $context_wc = $context_w . $context_c;

            if (isset($context_dictionary[$context_wc])) {
                $context_w = $context_wc;
            } else {
                if (isset($context_dictionaryToCreate[$context_w])) {
                    if (self::charCodeAt($context_w, 0) < 256) {
                        for ($i = 0; $i < $context_numBits; $i++) {
                            $context_data_val = ($context_data_val << 1);
                            if ($context_data_position == $bitsPerChar - 1) {
                                $context_data_position = 0;
                                $context_data[] = $getCharFromInt($context_data_val);
                                $context_data_val = 0;
                            } else {
                                $context_data_position++;
                            }
                        }
                        $value = self::charCodeAt($context_w, 0);
                        for ($i = 0; $i < 8; $i++) {
                            $context_data_val = ($context_data_val << 1) | ($value & 1);
                            if ($context_data_position == $bitsPerChar - 1) {
                                $context_data_position = 0;
                                $context_data[] = $getCharFromInt($context_data_val);
                                $context_data_val = 0;
                            } else {
                                $context_data_position++;
                            }
                            $value = $value >> 1;
                        }
                    } else {
                        $value = 1;
                        for ($i = 0; $i < $context_numBits; $i++) {
                            $context_data_val = ($context_data_val << 1) | $value;
                            if ($context_data_position == $bitsPerChar - 1) {
                                $context_data_position = 0;
                                $context_data[] = $getCharFromInt($context_data_val);
                                $context_data_val = 0;
                            } else {
                                $context_data_position++;
                            }
                            $value = 0;
                        }
                        $value = self::charCodeAt($context_w, 0);
                        for ($i = 0; $i < 16; $i++) {
                            $context_data_val = ($context_data_val << 1) | ($value & 1);
                            if ($context_data_position == $bitsPerChar - 1) {
                                $context_data_position = 0;
                                $context_data[] = $getCharFromInt($context_data_val);
                                $context_data_val = 0;
                            } else {
                                $context_data_position++;
                            }
                            $value = $value >> 1;
                        }
                    }
                    $context_enlargeIn--;
                    if ($context_enlargeIn == 0) {
                        $context_enlargeIn = pow(2, $context_numBits);
                        $context_numBits++;
                    }
                    unset($context_dictionaryToCreate[$context_w]);
                } else {
                    $value = $context_dictionary[$context_w];
                    for ($i = 0; $i < $context_numBits; $i++) {
                        $context_data_val = ($context_data_val << 1) | ($value & 1);
                        if ($context_data_position == $bitsPerChar - 1) {
                            $context_data_position = 0;
                            $context_data[] = $getCharFromInt($context_data_val);
                            $context_data_val = 0;
                        } else {
                            $context_data_position++;
                        }
                        $value = $value >> 1;
                    }
                }
                $context_enlargeIn--;
                if ($context_enlargeIn == 0) {
                    $context_enlargeIn = pow(2, $context_numBits);
                    $context_numBits++;
                }
                // Add wc to the dictionary.
                $context_dictionary[$context_wc] = $context_dictSize++;
                $context_w = $context_c;
            }
        }

        // Output the code for w.
        if ($context_w !== "") {
            if (isset($context_dictionaryToCreate[$context_w])) {
                if (self::charCodeAt($context_w, 0) < 256) {
                    for ($i = 0; $i < $context_numBits; $i++) {
                        $context_data_val = ($context_data_val << 1);
                        if ($context_data_position == $bitsPerChar - 1) {
                            $context_data_position = 0;
                            $context_data[] = $getCharFromInt($context_data_val);
                            $context_data_val = 0;
                        } else {
                            $context_data_position++;
                        }
                    }
                    $value = self::charCodeAt($context_w, 0);
                    for ($i = 0; $i < 8; $i++) {
                        $context_data_val = ($context_data_val << 1) | ($value & 1);
                        if ($context_data_position == $bitsPerChar - 1) {
                            $context_data_position = 0;
                            $context_data[] = $getCharFromInt($context_data_val);
                            $context_data_val = 0;
                        } else {
                            $context_data_position++;
                        }
                        $value = $value >> 1;
                    }
                } else {
                    $value = 1;
                    for ($i = 0; $i < $context_numBits; $i++) {
                        $context_data_val = ($context_data_val << 1) | $value;
                        if ($context_data_position == $bitsPerChar - 1) {
                            $context_data_position = 0;
                            $context_data[] = $getCharFromInt($context_data_val);
                            $context_data_val = 0;
                        } else {
                            $context_data_position++;
                        }
                        $value = 0;
                    }
                    $value = self::charCodeAt($context_w, 0);
                    for ($i = 0; $i < 16; $i++) {
                        $context_data_val = ($context_data_val << 1) | ($value & 1);
                        if ($context_data_position == $bitsPerChar - 1) {
                            $context_data_position = 0;
                            $context_data[] = $getCharFromInt($context_data_val);
                            $context_data_val = 0;
                        } else {
                            $context_data_position++;
                        }
                        $value = $value >> 1;
                    }
                }
                $context_enlargeIn--;
                if ($context_enlargeIn == 0) {
                    $context_enlargeIn = pow(2, $context_numBits);
                    $context_numBits++;
                }
                unset($context_dictionaryToCreate[$context_w]);
            } else {
                $value = $context_dictionary[$context_w];
                for ($i = 0; $i < $context_numBits; $i++) {
                    $context_data_val = ($context_data_val << 1) | ($value & 1);
                    if ($context_data_position == $bitsPerChar - 1) {
                        $context_data_position = 0;
                        $context_data[] = $getCharFromInt($context_data_val);
                        $context_data_val = 0;
                    } else {
                        $context_data_position++;
                    }
                    $value = $value >> 1;
                }
            }
            $context_enlargeIn--;
            if ($context_enlargeIn == 0) {
                $context_enlargeIn = pow(2, $context_numBits);
                $context_numBits++;
            }
        }

        // Mark the end of the stream
        $value = 2;
        for ($i = 0; $i < $context_numBits; $i++) {
            $context_data_val = ($context_data_val << 1) | ($value & 1);
            if ($context_data_position == $bitsPerChar - 1) {
                $context_data_position = 0;
                $context_data[] = $getCharFromInt($context_data_val);
                $context_data_val = 0;
            } else {
                $context_data_position++;
            }
            $value = $value >> 1;
        }

        // Flush the last char
        while (true) {
            $context_data_val = ($context_data_val << 1);
            if ($context_data_position == $bitsPerChar - 1) {
                $context_data[] = $getCharFromInt($context_data_val);
                break;
            } else {
                $context_data_position++;
            }
        }

        return implode('', $context_data);
    }

    /**
     * Modify suspend_data for SCORM slide navigation.
     *
     * Get the slide number from compressed suspend_data.
     *
     * This function decompresses the suspend_data and extracts the slide number
     * from the "l" field (which is 0-indexed, so we add 1 for 1-indexed result).
     *
     * @param string $suspendData Compressed suspend_data
     * @return int|null Slide number (1-indexed), or null on failure
     */
    public static function getSlideFromSuspendData(string $suspendData): ?int {
        if (empty($suspendData)) {
            return null;
        }

        try {
            $decompressed = self::decompressFromBase64($suspendData);

            if ($decompressed && strlen($decompressed) > 0) {
                // Look for "l" field (0-indexed slide position)
                if (preg_match('/"l"\s*:\s*(\d+)/', $decompressed, $matches)) {
                    $slideIndex = (int)$matches[1]; // 0-indexed
                    return $slideIndex + 1; // Convert to 1-indexed
                }

                // Fallback: look for "resume" field format "scene_slide"
                if (preg_match('/"resume"\s*:\s*"(\d+)_(\d+)"/', $decompressed, $matches)) {
                    $slideIndex = (int)$matches[2]; // 0-indexed
                    return $slideIndex + 1; // Convert to 1-indexed
                }
            }
        } catch (\Exception $e) {
            debugging("[lzstring_helper] getSlideFromSuspendData exception: " . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }

    /**
     * Modify suspend_data to set a specific slide position.
     *
     * This function takes compressed suspend_data, decompresses it,
     * modifies BOTH the "l" field AND the "resume" field to point to the
     * target slide, then recompresses.
     *
     * @param string $suspendData Original compressed suspend_data
     * @param int $targetSlide Target slide number (1-indexed)
     * @return string|null Modified compressed suspend_data, or null on failure
     */
    public static function modifySuspendDataSlide(string $suspendData, int $targetSlide): ?string {
        if (empty($suspendData)) {
            return null;
        }

        $targetIndex = $targetSlide - 1; // Convert to 0-indexed

        // Try LZ decompression
        try {
            $decompressed = self::decompressFromBase64($suspendData);

            if ($decompressed && strlen($decompressed) > 0) {
                $modified = $decompressed;
                $anyChange = false;

                // 1. Modify the "l" field (last slide position, 0-indexed)
                $modified = preg_replace_callback(
                    '/"l"\s*:\s*(\d+)/',
                    function($matches) use ($targetIndex, &$anyChange) {
                        if ((int)$matches[1] !== $targetIndex) {
                            $anyChange = true;
                        }
                        return '"l":' . $targetIndex;
                    },
                    $modified
                );

                // 2. Modify "resume" field - scene_slide format "0_7"
                // Keep the scene number, only change the slide number
                $modified = preg_replace_callback(
                    '/"resume"\s*:\s*"(\d+)_(\d+)"/',
                    function($matches) use ($targetIndex, &$anyChange) {
                        if ((int)$matches[2] !== $targetIndex) {
                            $anyChange = true;
                        }
                        return '"resume":"' . $matches[1] . '_' . $targetIndex . '"';
                    },
                    $modified
                );

                // 3. Modify d-array Resume variable - {"n":"Resume","v":"0_7"}
                $modified = preg_replace_callback(
                    '/("n"\s*:\s*"Resume"\s*,\s*"v"\s*:\s*")(\d+)_(\d+)(")/i',
                    function($matches) use ($targetIndex, &$anyChange) {
                        if ((int)$matches[3] !== $targetIndex) {
                            $anyChange = true;
                        }
                        return $matches[1] . $matches[2] . '_' . $targetIndex . $matches[4];
                    },
                    $modified
                );

                // 4. Modify reverse d-array - {"v":"0_7","n":"Resume"}
                $modified = preg_replace_callback(
                    '/("v"\s*:\s*")(\d+)_(\d+)("\s*,\s*"n"\s*:\s*"Resume")/i',
                    function($matches) use ($targetIndex, &$anyChange) {
                        if ((int)$matches[3] !== $targetIndex) {
                            $anyChange = true;
                        }
                        return $matches[1] . $matches[2] . '_' . $targetIndex . $matches[4];
                    },
                    $modified
                );

                if ($anyChange && $modified !== $decompressed) {
                    // Re-compress
                    $recompressed = self::compressToBase64($modified);
                    if ($recompressed) {
                        return $recompressed;
                    }
                }
            }
        } catch (\Exception $e) {
            debugging('LZ-String decompression failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }

        return null;
    }
}
