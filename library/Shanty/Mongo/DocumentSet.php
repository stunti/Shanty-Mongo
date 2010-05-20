<?php

/**
 * @category   Shanty
 * @package    Shanty_Mongo
 * @copyright  Shanty Tech Pty Ltd
 * @license    New BSD License
 * @author     Coen Hyde
 */
class Shanty_Mongo_DocumentSet extends Shanty_Mongo_Document
{
	const DYNAMIC_INDEX = '$';
	
	/**
	 * Get a property
	 * 
	 * @param mixed $property
	 */
	public function getProperty($index = null)
	{
		$new = is_null($index);
		
		// If property exists and initialised then return it
		if (!$new && array_key_exists($index, $this->_data)) {
			return $this->_data[$index];
		}
			
		// Fetch clean data for this property
		if (!$new && array_key_exists($index, $this->_cleanData)) $data = $this->_cleanData[$index];
		else $data = array();
		
		// If property is a reference to another document then fetch the reference document
		if (MongoDBRef::isRef($data)) {
			$collection = $data['$ref'];
			$data = MongoDBRef::get(static::getMongoDB(), $data);
			
			// If this is a broken reference then no point keeping it for later
			if (!$data) {
				$this->_data[$index] = null;
				return $this->_data[$index];
			}
			
			$reference = true;
		}
		else {
			$reference = false;
			$collection = $this->getCollection();
		}
		
		$config = array ();
		$config['collection'] = $collection;
		$config['requirementModifiers'] = $this->getRequirements(self::DYNAMIC_INDEX.'.');
		$config['parentIsArray'] = true;
		$config['hasId'] = $this->hasRequirement(self::DYNAMIC_INDEX, 'hasId');
		
		if (!$reference) {
			// If this is a new array element. We will $push to the array when saving
			if ($new) $path = $this->getPathToDocument();
			else $path = $this->getPathToProperty($index);
			
			$config['pathToDocument'] = $path;
			$config['criteria'] = $this->getCriteria();
		}
		
		// get the document class
		if (!$className = $this->hasRequirement(self::DYNAMIC_INDEX, 'Document')) {
			$className = 'Shanty_Mongo_Document';
		}
		
		// Make sure document class is a document
		if ($className !== 'Shanty_Mongo_Document' && !is_subclass_of($className, 'Shanty_Mongo_Document')) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception("{$className} is not a Shanty_Mongo_Document");
		}
		
		// If this is a new document and document will be saved as a reference, make sure it has a collection to be saved to
		if ($new && $this->hasRequirement(static::DYNAMIC_INDEX, 'AsReference') && !$className::hasCollectionName()) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception("Document class of '{$className}' is not associated with a collection");
		}
		
		$document = new $className($data, $config);
		
		// if this document was a reference then remember that
		if ($reference) {
			$this->_references->attach($document);
		}
		
		// If this is not a new document cache it
		if (!$new) {
			$this->_data[$index] = $document;
		}
		
		return $document;
	}
	
	/**
	 * Set property
	 * 
	 * @param $index
	 * @param $document
	 */
	public function setProperty($index, $document)
	{
		$new = is_null($index);
		
		// Make sure index is numeric
		if (!$new && !is_numeric($index)) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception("Index must be numeric '{$index}' given");
		}
		
		// Unset element
		if (!$new && is_null($value)) {
			$this->_data[$index] = null;
			return;
		}
		
		// Make sure this document is a Shanty_Mongo_Document
		if (!($document instanceof Shanty_Mongo_Document)) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception("Index must be numeric '{$index}' given");
		}
		
		// Make sure we are not keeping a copy of the old document in reference memory
		if (!$new && isset($this->_data[$index]) && !is_null($this->_data[$index])) {
			$this->_references->detach($this->_data[$index]);
		}
		
		// Throw exception if value is not valid
		$validators = $this->getValidators(self::DYNAMIC_INDEX);
		
		if (!$validators->isValid($document)) {
			require_once 'Shanty/Mongo/Exception.php';
			throw new Shanty_Mongo_Exception(implode($validators->getMessages(), "\n"));
		}
		
		// Clone document if it has been saved somewhere else
		if (!$document->isNewDocument()) {
			$document = clone $document;
		}
			
		// Filter value
//		$value = $this->getFilters(self::DYNAMIC_INDEX)->filter($document);
		
		if ($new) {
			$keys = $this->getPropertyKeys();
			$index = empty($keys) ? 0 : max($keys)+1;
			$this->_data[$index] = $document;
		}
		else $this->_data[$index] = $document;

		// Inform the document of it's surroundings
		$document->setCollection($this->getCollection());
		$document->setPathToDocument($this->getPathToProperty($index));
		$document->setConfigAttribute('criteria', $this->getCriteria());
	}
	
	/**
	 * Export all data
	 * 
	 * @return array
	 */
	public function export()
	{
		// Since this is an array, fill in empty index's with null
		$exportData = parent::export();
		$maxKey = max(array_keys($exportData));
		
		for ($i = 0; $i<$maxKey; $i++) {
			if (array_key_exists($i, $exportData)) continue;
			
			$exportData[$i] = null;
		}
		
		ksort($exportData);
		
		return $exportData;
	}
	
	/**
	 * Add a document to this set
	 * 
	 * @param Shanty_Mongo_Document $document
	 */
	public function addDocument(Shanty_Mongo_Document $document)
	{
		return $this->setProperty(null, $document);
	}
	
	/**
	 * Add a document to the push queue
	 * 
	 * @param Shanty_Mongo_Document $document
	 */
	public function pushDocument(Shanty_Mongo_Document $document)
	{
		$this->push($this->pathToDocument(), $document);
	}
	
	/**
	 * Get all operations
	 * 
	 * @param Boolean $includingChildren Get operations from children as well
	 */
	public function getOperations($includingChildren = false)
	{
		if ($this->hasRequirement(self::DYNAMIC_INDEX, 'AsReference')) $includingChildren = false;
		
		return parent::getOperations($includingChildren);
	}
	
	/**
	 * Remove all operations
	 * 
	 * @param Boolean $includingChildren Remove operations from children as wells
	 */
	public function purgeOperations($includingChildren = false)
	{
		if ($this->hasRequirement(self::DYNAMIC_INDEX, 'AsReference')) $includingChildren = false;
		
		return parent::purgeOperations($includingChildren);
	}
	
	public function __call($name, $arguments = array())
	{
		switch ($name) {
			case 'new':
				return $this->getProperty();
		}
		
		return call_user_func_array(array($this, $name), $arguments);
	}
}