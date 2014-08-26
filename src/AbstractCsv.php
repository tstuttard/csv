<?php
/**
* This file is part of the League.csv library
*
* @license http://opensource.org/licenses/MIT
* @link https://github.com/thephpleague/csv/
* @version 6.0.0
* @package League.csv
*
* For the full copyright and license information, please view the LICENSE
* file that was distributed with this source code.
*/
namespace League\Csv;

use InvalidArgumentException;
use IteratorAggregate;
use JsonSerializable;
use League\Csv\Config\Controls;
use League\Csv\Config\Formatter;
use League\Csv\Config\StreamFilter;
use SplFileInfo;
use SplFileObject;
use SplTempFileObject;

/**
 *  An abstract class to enable basic CSV manipulation
 *
 * @package League.csv
 * @since  4.0.0
 *
 */
abstract class AbstractCsv implements JsonSerializable, IteratorAggregate
{
    /**
     *  Csv Controls Trait
     */
    use Controls;

    /**
     *  Csv Formatter Trait
     */
    use Formatter;

    /**
     *  Stream Filter API Trait
     */
    use StreamFilter;

    /**
     * The constructor path
     *
     * can be a SplFileInfo object or the string path to a file
     *
     * @var \SplFileObject|string
     */
    protected $path;

    /**
     * The file open mode flag
     *
     * @var string
     */
    protected $open_mode;

    /**
     * The constructor
     *
     * The path must be an SplFileInfo object
     * an object that implements the `__toString` method
     * a path to a file
     *
     * @param \SplFileInfo|object|string $path      The file path
     * @param string                     $open_mode the file open mode flag
     */
    public function __construct($path, $open_mode = 'r+')
    {
        ini_set("auto_detect_line_endings", '1');

        $this->path = $this->normalizePath($path);
        $this->open_mode = strtolower($open_mode);
        $this->initStreamFilter($this->path);
    }

    /**
     * The destructor
     */
    public function __destruct()
    {
        //in case $this->path is a SplFileObject we need to remove its reference
        $this->path = null;
    }

    /**
     * Create a {@link AbstractCsv} from a string
     *
     * The path can be:
     * - an SplFileInfo,
     * - a SplFileObject,
     * - an object that implements the `__toString` method,
     * - a string
     *
     * BUT NOT a SplTempFileObject
     *
     * ```php
     *<?php
     * $csv = new Reader::createFromPath('/path/to/file.csv', 'a+');
     * $csv = new Reader::createFromPath(new SplFileInfo('/path/to/file.csv'));
     * $csv = new Reader::createFromPath(new SplFileObject('/path/to/file.csv'), 'rb');
     *
     * ?>
     * ```
     *
     * @param \SplFileInfo|\SplFileObject|object|string $path      file path
     * @param string                                    $open_mode the file open mode flag
     *
     * @return static
     *
     * @throws \InvalidArgumentException If $path is a \SplTempFileObject object
     */
    public static function createFromPath($path, $open_mode = 'r+')
    {
        if ($path instanceof SplTempFileObject) {
            throw new InvalidArgumentException('an `SplTempFileObject` object does not contain a valid path');
        } elseif ($path instanceof SplFileInfo) {
            $path = $path->getPath().'/'.$path->getBasename();
        }

        $path = (string) $path;
        $path = trim($path);

        return new static($path, $open_mode);
    }

    /**
     * Create a {@link AbstractCsv} from a SplFileObject
     *
     * The path can be:
     * - a SplFileObject,
     * - a SplTempFileObject
     *
     * ```php
     *<?php
     * $csv = new Writer::createFromFileObject(new SplFileInfo('/path/to/file.csv'));
     * $csv = new Writer::createFromFileObject(new SplTempFileObject);
     *
     * ?>
     * ```
     *
     * @param SplFileObject $obj
     *
     * @return static
     */
    public static function createFromFileObject(SplFileObject $obj)
    {
        return new static($obj);
    }

    /**
     * Create a {@link AbstractCsv} from a string
     *
     * The string must be an object that implements the `__toString` method,
     * or a string
     *
     * @param string|object $str the string
     *
     * @return static
     *
     * @throws \InvalidArgumentException If the data provided is invalid
     */
    public static function createFromString($str)
    {
        if (! self::isValidString($str)) {
            throw new InvalidArgumentException(
                'the submitted data must be a string or an object implementing the `__toString` method'
            );
        }
        $obj = new SplTempFileObject;
        $obj->fwrite((string) $str.PHP_EOL);

        return static::createFromFileObject($obj);
    }

    /**
     * Return a normalize path which could be a SplFileObject
     * or a string path
     *
     * @param object|string $path the filepath
     *
     * @return \SplFileObject|string
     */
    protected function normalizePath($path)
    {
        if ($path instanceof SplFileObject) {
            return $path;
        } elseif ($path instanceof SplFileInfo) {
            return $path->getPath().'/'.$path->getBasename();
        }

        $path = (string) $path;
        $path = trim($path);

        return $path;
    }

    /**
     * Create a {@link AbstractCsv} instance from another {@link AbstractCsv} object
     *
     * @param string $class_name the class to be instantiated
     * @param string $open_mode  the file open mode flag
     *
     * @return static
     */
    protected function newInstance($class_name, $open_mode)
    {
        $csv = new $class_name($this->path, $open_mode);
        $csv->delimiter = $this->delimiter;
        $csv->enclosure = $this->enclosure;
        $csv->escape = $this->escape;
        $csv->encodingFrom = $this->encodingFrom;

        return $csv;
    }

    /**
     * Create a {@link Writer} instance from a {@link AbstractCsv} object
     *
     * @param string $open_mode the file open mode flag
     *
     * @return \League\Csv\Writer object
     */
    public function newWriter($open_mode = 'r+')
    {
        return $this->newInstance('\League\Csv\Writer', $open_mode);
    }

    /**
     * Create a {@link Reader} instance from a {@link AbstractCsv} object
     *
     * @param string $open_mode the file open mode flag
     *
     * @return \League\Csv\Reader object
     */
    public function newReader($open_mode = 'r+')
    {
        return $this->newInstance('\League\Csv\Reader', $open_mode);
    }

    /**
     * Return the CSV Iterator
     *
     * @return \SplFileObject
     */
    public function getIterator()
    {
        $obj = $this->path;
        if (! $obj instanceof SplFileObject) {
            $obj = new SplFileObject($this->getStreamFilterPath(), $this->open_mode);
        }
        $obj->setCsvControl($this->delimiter, $this->enclosure, $this->escape);
        $obj->setFlags($this->flags);

        return $obj;
    }

    /**
     * JsonSerializable Interface
     *
     * @return array
     */
    public function jsonSerialize()
    {
        return iterator_to_array($this->convertToUtf8($this->getIterator()), false);
    }

    /**
    * Validate a variable to be stringable
    *
    * @param mixed $str
    *
    * @return boolean
    */
    public static function isValidString($str)
    {
        return is_scalar($str) || (is_object($str) && method_exists($str, '__toString'));
    }
}
