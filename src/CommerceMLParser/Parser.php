<?php
/**
 * Created by PhpStorm.
 * User: Ivan Koretskiy aka gillbeits[at]gmail.com
 * Date: 17/04/15
 * Time: 19:47
 */

namespace CommerceMLParser;


use CommerceMLParser\Exception\NoEventException;
use Symfony\Component\EventDispatcher\EventDispatcher;

class Parser extends EventDispatcher {
    protected $xmlReader;
    protected $factory;
    protected $callable = array();

    private $bulk_count = 0;
    private $path = array();
    private $bulk_rows = array();
    private $bulk_rows_counter = array();


    public function __construct(Factory $factory = null)
    {
        if (null == $factory) {
            $factory = new Factory();
        }

        $this->xmlReader = new \XMLReader();
        $this->factory = $factory;

        $this->registerPath('КоммерческаяИнформация/Классификатор/Группы/Группа', $this->dispatchObjectCallable());
        $this->registerPath('КоммерческаяИнформация/Классификатор/Свойства/СвойствоНоменклатуры', $this->dispatchObjectCallable());
        $this->registerPath('КоммерческаяИнформация/Каталог/Товары/Товар', $this->dispatchObjectCallable());

        $this->registerPath('КоммерческаяИнформация/ПакетПредложений/ТипыЦен/ТипЦены', $this->dispatchObjectCallable());
        $this->registerPath('КоммерческаяИнформация/ПакетПредложений/Предложения/Предложение', $this->dispatchObjectCallable());
    }

    /**
     * @return callable
     */
    protected function dispatchObjectCallable()
    {
        $class = $this;
        return function ($object, $self) use($class) {
            if (!class_exists($object[1]['event'])) {
                throw new NoEventException($object[1]);
            }
            $event = explode('\\', $object[1]['event']);
            $event = end($event);
            $class->dispatch($event, new $object[1]['event']($object[0], $self));
        };
    }

    /**
     * @return array
     */
    public function getBulkRowsCounter($event)
    {
        return $this->bulk_rows_counter[$event];
    }

    public function parse($file)
    {
        $this->path = array();

        $this->xmlReader->open($file);
        $this->read();
        $this->xmlReader->close();
    }

    /**
     * @param string $path
     * @param callable|callback $callable
     * @return $this
     */
    public function registerPath($path, $callable)
    {
        $this->callable[$path] = $callable;
        return $this;
    }

    protected function read()
    {
        $shop = null;
        $xml = $this->xmlReader;
        while ($xml->read()) {
            if ($xml->nodeType == \XMLReader::END_ELEMENT) {
                array_pop($this->path);
                continue;
            }


            if ($xml->nodeType == \XMLReader::ELEMENT) {
                array_push($this->path, $xml->name);
                $path = implode('/', $this->path);

                if ($xml->isEmptyElement) {
                    array_pop($this->path);
                }

                if (isset($this->callable[$path])) {
                    $object = $this->factory->createObject($path, $this->loadElementXml());
                    call_user_func_array($this->callable[$path], array($object, $this));
                }
            }
        }

        foreach ($this->bulk_rows as $event => $rows) {
            if (!empty($rows)) {
                $this->dispatch('BulkUpload', new BulkEvent($event, $this));
            }
        }
    }

    public function addRow($event, $obj)
    {
        $this->bulk_rows[$event][] = $obj;
        @$this->bulk_rows_counter[$event]++;
        if (count($this->bulk_rows[$event]) >= $this->bulk_count) {
            $this->dispatch('BulkUpload', new BulkEvent($event, $this));
            $this->bulk_rows[$event] = array();
        }
    }

    public function getRows($event = null)
    {
        return null!== $event ? $this->bulk_rows[$event] : $this->bulk_rows;
    }

    /**
     * @return \SimpleXMLElement
     */
    protected function loadElementXml()
    {
        $xml = $this->xmlReader->readOuterXml();

        return simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?>' . $xml);
    }

    /**
     * @return int
     */
    public function getBulkCount()
    {
        return $this->bulk_count;
    }

    /**
     * @param int $bulk_count
     */
    public function setBulkCount($bulk_count)
    {
        $this->bulk_count = $bulk_count;
        return $this;
    }
}