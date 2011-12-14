<?php

// http://schemas.opengis.net/kml/2.2.0/ogckml22.xsd
// http://portal.opengeospatial.org/files/?artifact_id=27810
// http://code.google.com/apis/kml/documentation/kmlreference.html

includePackage('Maps', 'KML');

class KMLDataParser extends XMLDataParser implements MapDataParser
{
    protected $elementStack = array();
    //protected $data='';
    protected $feedId;

    protected $document;
    protected $folders = array();
    protected $placemarks = array();
    protected $title;
    //protected $category;
    protected $itemCount = 0;
    protected $otherCategory;

    protected $parseMode=self::PARSE_MODE_STRING;
    
    // whitelists
    protected static $startElements=array(
        'DOCUMENT', 'FOLDER',
        'STYLE', 'STYLEMAP',
        'PLACEMARK', 'POINT', 'LINESTRING', 'LINEARRING', 'POLYGON'
        );
    protected static $endElements=array(
        'DOCUMENT', 
        'STYLE', 'STYLEMAP', 'STYLEURL',
        );
    
    public function init($args) {
        parent::init($args);
        $this->feedId = mapIdForFeedData($args);
    }

    /////// MapDataParser

    public function placemarks() {
        return $this->placemarks;
    }

    public function categories() {
        return $this->folders;
    }

    public function getProjection() {
        return null;
    }

    /////

    protected function addPlacemark(Placemark $placemark)
    {
        if (!$this->otherCategory) {
            $this->otherCategory = new KMLFolder('Folder', array());
            $this->otherCategory->setName('Other Places'); // TODO: get localized string
            $this->otherCategory->setId($this->getId().'-other');
            $this->addFolder($this->otherCategory); // TODO: make sure this sorts to the bottom
        }

        $this->otherCategory->addItem($placemark);
    }

    protected function addFolder(MapFolder $folder)
    {
        $folder->setParent($this);
        $this->folders[] = $folder;
        $this->itemCount++;
    }

    public function getId()
    {
        return $this->feedId;
    }

    public function getTitle() {
        return $this->title;
    }

    public function getStyle($id) {
        if (substr($id, 0, 1) == '#') {
            $id = substr($id, 1);
        }
        if (isset($this->styles[$id])) {
            return $this->styles[$id];
        }
        return null;
    }

    protected function shouldHandleStartElement($name)
    {
        return in_array($name, self::$startElements);
    }

    protected function handleStartElement($name, $attribs)
    {
        switch ($name)
        {
            case 'DOCUMENT':
                $this->elementStack[] = new KMLDocument($name, $attribs);
                break;
            case 'FOLDER':
                $parent = end($this->elementStack);
                $folder = new KMLFolder($name, $attribs);
                $this->elementStack[] = $folder;
                if ($parent instanceof KMLFolder) {
                    $parentCategory = $parent->getId();
                    $newFolderIndex = count($parent->categories());
                    $parent->addItem($folder);
                } else {
                    $parentCategory = $this->getId();
                    $newFolderIndex = $this->itemCount;
                    $this->addFolder($folder);
                }
                $folder->setId(substr(md5($parentCategory.$newFolderIndex), 0, strlen($parentCategory)-1)); // something unique
                break;
            case 'STYLE':
                $this->elementStack[] = new KMLStyle($name, $attribs);
                break;
            case 'STYLEMAP':
                $style = new KMLStyle($name, $attribs);
                $style->setStyleContainer($this);
                $this->elementStack[] = $style;
                break;
            case 'PLACEMARK':
                $placemark = new KMLPlacemark($name, $attribs);
                $parent = end($this->elementStack);
                $this->elementStack[] = $placemark;

                if ($parent instanceof KMLFolder) {
                    $parent->addItem($placemark);
                } else {
                    $this->addPlacemark($placemark);
                }
                break;
            case 'POINT':
                $this->elementStack[] = new KMLPoint($name, $attribs);
                break;
            case 'LINESTRING':
                $this->elementStack[] = new KMLLineString($name, $attribs);
                break;
            case 'LINEARRING':
                $this->elementStack[] = new KMLLinearRing($name, $attribs);
                break;
            case 'POLYGON':
                $this->elementStack[] = new KMLPolygon($name, $attribs);
                break;
        }
    }

    protected function shouldStripTags($element)
    {
        return false;
    }

    protected function shouldHandleEndElement($name)
    {
        return in_array($name, self::$endElements);
    }

    protected function handleEndElement($name, $element, $parent)
    {
        switch ($name)
        {
            case 'DOCUMENT':
                $this->title = $element->getTitle();
                break;
            case 'STYLE':
            case 'STYLEMAP':
                $this->styles[$element->getAttrib('ID')] = $element;
                break;
            case 'STYLEURL':
                $value = $element->value();
                if ($parent instanceof Placemark) {
                    if ($style = $this->getStyle($value)) {
                        $parent->setStyle($this->getStyle($value));
                    } else {
                        Kurogo::log(LOG_WARNING, "Style $value was not found", 'map');
                    }
                } else {
                    $parent->addElement($element);
                }
                break;
        }
    }

    public function clearInternalCache()
    {
        $this->docuemtn = null;
        $this->folders = array();
        $this->placemarks = array();
        $this->itemCount = 0;
        $this->otherCategory = null;
    }

    public function parseData($content)
    {
        $this->clearInternalCache();
        $this->parseXML($content);
        $items = $this->categories();
        $folder = $this;
        while (count($items) == 1) {
            $folder = current($items);
            $items = $folder->categories();
        }
        if (!$items) {
            $items = $folder->placemarks();
        }
        return $items;
    }
}



