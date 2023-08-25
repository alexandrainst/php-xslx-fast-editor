<?php

/**
 * PHP library to make basic but fast read & write operations on existing Excel workbooks.
 * Originally written by [Alexandre Alapetite](https://github.com/Alkarex) for the [Alexandra Institute](https://alexandra.dk/), 2023.
 *
 * @author Alexandre Alapetite <alexandre.alapetite@alexandra.dk>
 * @category PHP
 * @license https://gnu.org/licenses/agpl.html GNU AGPL
 * @link https://github.com/alexandrainst/php-xlsx-fast-editor
 * @package XlsxFastEditor
 */

namespace alexandrainst\XlsxFastEditor;

/**
 * Main class to fast edit an existing XLSX/XLSM document (Microsoft Excel 2007+, Office Open XML Workbook)
 * using low-level ZIP and XML manipulation.
 */
final class XlsxFastEditor
{
	/** @internal */
	public const _OXML_NAMESPACE = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

	private const CALC_CHAIN_CACHE_PATH = 'xl/calcChain.xml';
	private const SHARED_STRINGS_PATH = 'xl/sharedStrings.xml';
	private const WORKBOOK_PATH = 'xl/workbook.xml';

	private \ZipArchive $zip;

	/**
	 * Cache of the XPath instances associated to the DOM of the XML documents.
	 * @var array<string,\DOMXPath>
	 */
	private array $documents = [];

	/**
	 * Track which documents have pending changes.
	 * @var array<string,bool>
	 */
	private array $modified = [];

	/**
	 * Whether the calcChain must be cleared on save.
	 */
	private bool $mustClearCalcChain = false;

	public function __construct(string $filename)
	{
		$this->zip = new \ZipArchive();
		$zipCode = $this->zip->open($filename, \ZipArchive::CREATE);
		if ($zipCode !== true) {
			throw new XlsxFastEditorZipException("Cannot open workbook {$filename}!", $zipCode);
		}
	}

	/**
	 * Mark a document fragment as modified.
	 * @param string $path The path of the document inside the ZIP document.
	 */
	private function touchPath(string $path): void
	{
		$this->modified[$path] = true;
	}

	/**
	 * Mark a document fragment as modified.
	 * @internal
	 * @param int $sheetNumber Worksheet number (base 1)
	 */
	public function _touchWorksheet(int $sheetNumber): void
	{
		$path = self::getWorksheetPath($sheetNumber);
		$this->touchPath($path);
	}

	/**
	 * Will clear the calcChain on save.
	 * @internal
	 */
	public function _clearCalcChain(): void
	{
		$this->mustClearCalcChain = true;
	}

	/**
	 * Close the underlying document archive.
	 * Note: changes need to be explicitly saved before (see `XlsxFastEditor::save()`)
	 * Note: the object should not be used anymore afterwards.
	 */
	public function close(): void
	{
		$this->documents = [];
		if (!$this->zip->close()) {
			throw new XlsxFastEditorZipException("Error closing the underlying document!");
		}
	}

	/**
	 * Saves the modified document fragments.
	 * @param bool $close Automatically close the underlying document archive (see `XlsxFastEditor::close()`)
	 */
	public function save(bool $close = true): void
	{
		if ($this->mustClearCalcChain) {
			// Removes calcChain.xml, which contains some cache for formulas,
			// as the cache might become invalid after writing in cells containing formulas.
			$this->zip->deleteName(self::CALC_CHAIN_CACHE_PATH);
			$this->mustClearCalcChain = false;
		}
		foreach ($this->modified as $name => $pending) {
			if (!$pending || !isset($this->documents[$name])) {
				continue;
			}
			$xpath = $this->documents[$name];
			if (!$this->zip->deleteName($name)) {
				throw new XlsxFastEditorZipException("Error deleting old fragment {$name}!");
			}
			$dom = $xpath->document;
			$xml = $dom->saveXML();
			if ($xml === false) {
				throw new XlsxFastEditorXmlException("Error saving changes {$name}!");
			}
			if (!$this->zip->addFromString($name, $xml)) {
				throw new XlsxFastEditorZipException("Error saving new fragment {$name}!");
			}
		}
		$this->modified = [];

		if ($close) {
			$this->close();
		}
	}

	/**
	 * Extracts a worksheet from the internal ZIP document,
	 * parse the XML, open the DOM, and
	 * returns an XPath instance associated to the DOM at the given XML path.
	 * The XPath instance is then cached.
	 * @param string $path The path of the document inside the ZIP document.
	 */
	private function getXPathFromPath(string $path): \DOMXPath
	{
		if (isset($this->documents[$path])) {
			return $this->documents[$path];
		}

		$xml = $this->zip->getFromName($path);
		if ($xml === false) {
			throw new XlsxFastEditorFileFormatException("Missing XML fragment {$path}!");
		}

		$dom = new \DOMDocument();
		if ($dom->loadXML($xml, LIBXML_NOERROR | LIBXML_NONET | LIBXML_NOWARNING) === false) {
			throw new XlsxFastEditorXmlException("Error reading XML fragment {$path}!");
		}

		$xpath = new \DOMXPath($dom);
		$xpath->registerNamespace('o', self::_OXML_NAMESPACE);

		$this->documents[$path] = $xpath;
		return $xpath;
	}

	/**
	 * Returns a DOM document of the given XML path.
	 * @param string $path The path of the document inside the ZIP document.
	 */
	private function getDomFromPath(string $path): \DOMDocument
	{
		return $this->getXPathFromPath($path)->document;
	}

	/**
	 * Count the number of worksheets in the workbook.
	 */
	public function getWorksheetCount(): int
	{
		$xpath = $this->getXPathFromPath(self::WORKBOOK_PATH);
		$count = $xpath->evaluate('count(/o:workbook/o:sheets/o:sheet)');
		return is_numeric($count) ? (int)$count : 0;
	}

	/**
	 * Get a worksheet number (ID) from its name (base 1).
	 * @param string $sheetName The name of the worksheet to look up.
	 * @return int The worksheet ID, or -1 if not found.
	 */
	public function getWorksheetNumber(string $sheetName): int
	{
		$xpath = $this->getXPathFromPath(self::WORKBOOK_PATH);
		$sheetId = $xpath->evaluate("normalize-space(/o:workbook/o:sheets/o:sheet[@name='$sheetName'][1]/@sheetId)");
		if (is_string($sheetId)) {
			return (int)$sheetId;
		}
		return -1;
	}

	/**
	 * Get a worksheet name from its number (ID).
	 * @param int $sheetNumber The number of the worksheet to look up.
	 * @return string|null The worksheet name, or null if not found.
	 */
	public function getWorksheetName(int $sheetNumber): ?string
	{
		$xpath = $this->getXPathFromPath(self::WORKBOOK_PATH);
		$sheetName = $xpath->evaluate("normalize-space(/o:workbook/o:sheets/o:sheet[$sheetNumber][1]/@name)");
		return is_string($sheetName) ? $sheetName : null;
	}

	private static function getWorksheetPath(int $sheetNumber): string
	{
		return "xl/worksheets/sheet{$sheetNumber}.xml";
	}

	/**
	 * Defines the *Full calculation on load* policy for the specified worksheet.
	 * @param int $sheetNumber Worksheet number (base 1)
	 */
	public function setFullCalcOnLoad(int $sheetNumber, bool $value): void
	{
		$this->mustClearCalcChain = true;
		$dom = $this->getDomFromPath(self::getWorksheetPath($sheetNumber));
		$sheetCalcPr = null;
		$sheetCalcPrs = $dom->getElementsByTagName('sheetCalcPr');
		if ($sheetCalcPrs->length > 0) {
			$sheetCalcPr = $sheetCalcPrs[0];
		} else {
			$sheetDatas = $dom->getElementsByTagName('sheetData');
			if ($sheetDatas->length > 0) {
				$sheetData = $sheetDatas[0];
				if ($sheetData instanceof \DOMElement) {
					$sheetCalcPr = $dom->createElement('sheetCalcPr');
					if ($sheetCalcPr !== false && $sheetData->parentNode !== null) {
						$sheetData->parentNode->insertBefore($sheetCalcPr, $sheetData->nextSibling);
					}
				}
			}
		}
		if ($sheetCalcPr instanceof \DOMElement) {
			$sheetCalcPr->setAttribute('fullCalcOnLoad', $value ? 'true' : 'false');
			$this->_touchWorksheet($sheetNumber);
		}
	}

	/**
	 * Get the row of the given number in the given worksheet.
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param int $rowNumber Number (ID) of the row (base 1). Warning: this is not an index (not all rows necessarily exist in a sequence)
	 * @return XlsxFastEditorRow|null The row of that number in that worksheet if it exists, null otherwise.
	 * @param int $accessMode To control the behaviour when the cell does not exist:
	 * set to `XlsxFastEditor::ACCESS_MODE_NULL` to return `null` (default),
	 * set to `XlsxFastEditor::ACCESS_MODE_EXCEPTION` to raise an `XlsxFastEditorInputException` exception,
	 * set to `XlsxFastEditor::ACCESS_MODE_AUTOCREATE` to auto-create the cell.
	 * @return XlsxFastEditorRow|null A row, potentially `null` if the row does not exist and `$accessMode` is set to `XlsxFastEditor::ACCESS_MODE_NULL`
	 * @phpstan-return ($accessMode is XlsxFastEditor::ACCESS_MODE_NULL ? XlsxFastEditorRow|null : XlsxFastEditorRow)
	 */
	public function getRow(int $sheetNumber, int $rowNumber, int $accessMode = XlsxFastEditor::ACCESS_MODE_NULL): ?XlsxFastEditorRow
	{
		$xpath = $this->getXPathFromPath(self::getWorksheetPath($sheetNumber));
		$rows = $xpath->query("/o:worksheet/o:sheetData/o:row[@r='{$rowNumber}'][1]");
		$row = null;
		if ($rows !== false && $rows->length > 0) {
			$row = $rows[0];
			if (!($row instanceof \DOMElement)) {
				throw new XlsxFastEditorXmlException("Error querying XML fragment for row {$sheetNumber} of worksheet {$sheetNumber}!");
			}
		}

		if ($row === null) {
			// The <row> was not found

			switch ($accessMode) {
				case XlsxFastEditor::ACCESS_MODE_EXCEPTION:
					throw new XlsxFastEditorInputException("Row {$sheetNumber}/{$rowNumber} not found!");
				case XlsxFastEditor::ACCESS_MODE_AUTOCREATE:
					$sheetDatas = $xpath->document->getElementsByTagName('sheetData');
					if ($sheetDatas->length === 0) {
						throw new XlsxFastEditorXmlException("Cannot find sheetData for worksheet {$sheetNumber}!");
					}
					$sheetData = $sheetDatas[0];
					if (!($sheetData instanceof \DOMElement)) {
						throw new XlsxFastEditorXmlException("Error querying XML fragment for worksheet {$sheetNumber}!");
					}
					$row = $xpath->document->createElement('row');
					if ($row === false) {
						throw new XlsxFastEditorXmlException("Error creating row {$sheetNumber}/{$rowNumber}!");
					}
					$row->setAttribute('r', (string)$rowNumber);

					// Excel expects the lines to be sorted
					$sibling = $sheetData->firstElementChild;
					while ($sibling !== null && (int)$sibling->getAttribute('r') < $rowNumber) {
						$sibling = $sibling->nextElementSibling;
					}
					$sheetData->insertBefore($row, $sibling);
					break;
				default:
				case XlsxFastEditor::ACCESS_MODE_NULL:
					return null;
			}
		}

		return new XlsxFastEditorRow($this, $sheetNumber, $row);
	}

	/**
	 * Get the first existing row of the worksheet.
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @return XlsxFastEditorRow|null The first row of the worksheet if there is any row, null otherwise.
	 */
	public function getFirstRow(int $sheetNumber): ?XlsxFastEditorRow
	{
		$xpath = $this->getXPathFromPath(self::getWorksheetPath($sheetNumber));
		$rs = $xpath->query("/o:worksheet/o:sheetData/o:row[position() = 1]");
		if ($rs !== false && $rs->length > 0) {
			$r = $rs[0];
			if (!($r instanceof \DOMElement)) {
				throw new XlsxFastEditorXmlException("Error querying XML fragment for row {$sheetNumber} of worksheet {$sheetNumber}!");
			}
			return new XlsxFastEditorRow($this, $sheetNumber, $r);
		}
		return null;
	}

	/**
	 * Get the last existing row of the worksheet.
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @return XlsxFastEditorRow|null The last row of the worksheet if there is any row, null otherwise.
	 */
	public function getLastRow(int $sheetNumber): ?XlsxFastEditorRow
	{
		$xpath = $this->getXPathFromPath(self::getWorksheetPath($sheetNumber));
		$rs = $xpath->query("/o:worksheet/o:sheetData/o:row[position() = last()]");
		if ($rs !== false && $rs->length > 0) {
			$r = $rs[0];
			if (!($r instanceof \DOMElement)) {
				throw new XlsxFastEditorXmlException("Error querying XML fragment for row {$sheetNumber} of worksheet {$sheetNumber}!");
			}
			return new XlsxFastEditorRow($this, $sheetNumber, $r);
		}
		return null;
	}

	/**
	 * Delete the specified row of the specified worksheet.
	 * @param int $sheetNumber Worksheet number (base 1)
	 */
	public function deleteRow(int $sheetNumber, int $rowNumber): bool
	{
		$xpath = $this->getXPathFromPath(self::getWorksheetPath($sheetNumber));
		$rs = $xpath->query("/o:worksheet/o:sheetData/o:row[@r='{$rowNumber}'][1]");
		if ($rs !== false && $rs->length > 0) {
			$r = $rs[0];
			if (!($r instanceof \DOMElement) || $r->parentNode === null) {
				throw new XlsxFastEditorXmlException("Error querying XML fragment for row {$sheetNumber} of worksheet {$sheetNumber}!");
			}
			return $r->parentNode->removeChild($r) != false;
		}
		return false;
	}

	/**
	 * To iterate over the all the rows of a given worksheet.
	 * @return \Traversable<XlsxFastEditorRow>
	 */
	public function rowsIterator(int $sheetNumber): \Traversable
	{
		$xpath = $this->getXPathFromPath(self::getWorksheetPath($sheetNumber));
		$rs = $xpath->query("/o:worksheet/o:sheetData/o:row");
		if ($rs !== false) {
			for ($i = 0; $i < $rs->length; $i++) {
				$r = $rs[$i];
				if (!($r instanceof \DOMElement)) {
					throw new XlsxFastEditorXmlException("Error querying XML fragment for row {$sheetNumber}!");
				}
				yield new XlsxFastEditorRow($this, $sheetNumber, $r);
			}
		}
	}

	/**
	 * Produce an array from a worksheet, indexed by column name (like `AB`) first, then line (like `12`).
	 * Only the existing lines and cells are included.
	 * @return array<string,array<int,null|string>> An array that can be access like `$array['AB'][12]`
	 */
	public function readArray(int $sheetNumber): array
	{
		$table = [];
		foreach ($this->rowsIterator($sheetNumber) as $row) {
			foreach ($row->cellsIterator() as $cell) {
				$table[$cell->column()][$row->number()] = $cell->readString();
			}
		}
		return $table;
	}

	/**
	 * Produce an array from a worksheet, indexed by column header (like `columnName`) first, then line (like `12`),
	 * having the column header defined in the first existing line of the spreadsheet.
	 * Only the existing lines and cells are included.
	 * @return array<string,array<int,null|string>> An array that can be access like `$array['columnName'][12]`
	 */
	public function readArrayWithHeaders(int $sheetNumber): array
	{
		$table = [];
		$headers = [];
		$firstRow = true;
		foreach ($this->rowsIterator($sheetNumber) as $row) {
			if ($firstRow) {
				foreach ($row->cellsIterator() as $cell) {
					$headers[$cell->column()] = $cell->readString();
				}
				$firstRow = false;
				continue;
			}
			foreach ($row->cellsIterator() as $cell) {
				$header = $headers[$cell->column()] ?? $cell->column();
				$table[$header][$row->number()] = $cell->readString();
			}
		}
		return $table;
	}

	/**
	 * Sort cells (such as `B3`, `AA23`) on column first (such as `B`, `AA`) and then line (such as `3`, `23`).
	 * @param $ref1 A cell reference such as `B3`
	 * @param $ref1 A cell reference such as `AA23`
	 * @return int -1 if $ref1 is before $ref2; 1 if $ref1 is greater than $ref2, and 0 if they are equal.
	 */
	public static function cellOrderCompare(string $ref1, string $ref2): int
	{
		if (preg_match('/^([A-Z]+)(\d+)$/', $ref1, $matches1) === 1 && preg_match('/^([A-Z]+)(\d+)$/', $ref2, $matches2) === 1) {
			$column1 = $matches1[1];
			$column2 = $matches2[1];
			$length1 = strlen($column1);
			$length2 = strlen($column2);
			if ($length1 !== $length2) {
				return $length1 <=> $length2;
			}
			$cmp = strcmp($column1, $column2);
			if ($cmp !== 0) {
				return $cmp;
			}
			$line1 = (int)$matches1[2];
			$line2 = (int)$matches2[2];
			return $line1 <=> $line2;
		}
		return strcmp($ref1, $ref2);
	}

	/** To return `null` when accessing a row or cell that does not exist, e.g. via {@see XlsxFastEditor::getCell()} */
	public const ACCESS_MODE_NULL = 0;
	/** To throw an exception when accessing a row or cell that does not exist, e.g. via {@see XlsxFastEditor::getCell()} */
	public const ACCESS_MODE_EXCEPTION = 1;
	/** To auto-create the cell when accessing a row or cell that does not exist, e.g. via {@see XlsxFastEditor::getCell()} */
	public const ACCESS_MODE_AUTOCREATE = 2;

	/**
	 * Access the specified cell in the specified worksheet. Can create it automatically if asked to.
	 * The corresponding row can also be automatically created if it does not exist already, but the worksheet cannot be automatically created.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 * @param int $accessMode To control the behaviour when the cell does not exist:
	 * set to `XlsxFastEditor::ACCESS_MODE_NULL` to return `null` (default),
	 * set to `XlsxFastEditor::ACCESS_MODE_EXCEPTION` to raise an `XlsxFastEditorInputException` exception,
	 * set to `XlsxFastEditor::ACCESS_MODE_AUTOCREATE` to auto-create the cell.
	 * @return XlsxFastEditorCell|null A cell, potentially `null` if the cell does not exist and `$accessMode` is set to `XlsxFastEditor::ACCESS_MODE_NULL`
	 * @phpstan-return ($accessMode is XlsxFastEditor::ACCESS_MODE_NULL ? XlsxFastEditorCell|null : XlsxFastEditorCell)
	 */
	public function getCell(int $sheetNumber, string $cellName, int $accessMode = XlsxFastEditor::ACCESS_MODE_NULL): ?XlsxFastEditorCell
	{
		if (!ctype_alnum($cellName)) {
			throw new XlsxFastEditorInputException("Invalid cell reference {$cellName}!");
		}
		$cellName = strtoupper($cellName);

		$xpath = $this->getXPathFromPath(self::getWorksheetPath($sheetNumber));
		$cs = $xpath->query("/o:worksheet/o:sheetData/o:row/o:c[@r='{$cellName}'][1]");
		$c = null;
		if ($cs !== false && $cs->length > 0) {
			$c = $cs[0];
			if (!($c instanceof \DOMElement)) {
				throw new XlsxFastEditorXmlException("Error querying XML fragment for cell {$sheetNumber}/{$cellName}!");
			}
		}

		if ($c === null) {
			// The cell <c> was not found

			switch ($accessMode) {
				case XlsxFastEditor::ACCESS_MODE_EXCEPTION:
					throw new XlsxFastEditorInputException("Internal error accessing cell {$sheetNumber}/{$cellName}!");
				case XlsxFastEditor::ACCESS_MODE_AUTOCREATE:
					$rowNumber = (int)preg_replace('/[^\d]+/', '', $cellName);
					if ($rowNumber === 0) {
						throw new XlsxFastEditorInputException("Invalid cell reference {$cellName}!");
					}
					$row = $this->getRow($sheetNumber, $rowNumber, $accessMode);
					return $row->getCell($cellName, $accessMode);
				default:
				case XlsxFastEditor::ACCESS_MODE_NULL:
					return null;
			}
		}

		return new XlsxFastEditorCell($this, $sheetNumber, $c);
	}

	/**
	 * Read a formula in the given worksheet at the given cell location.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function readFormula(int $sheetNumber, string $cellName): ?string
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_NULL);
		return $cell === null ? null : $cell->readFormula();
	}

	/**
	 * Read a float in the given worksheet at the given cell location.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function readFloat(int $sheetNumber, string $cellName): ?float
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_NULL);
		return $cell === null ? null : $cell->readFloat();
	}

	/**
	 * Read an integer in the given worksheet at the given cell location.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function readInt(int $sheetNumber, string $cellName): ?int
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_NULL);
		return $cell === null ? null : $cell->readInt();
	}

	/**
	 * Access a string stored in the shared strings list.
	 * @param int $stringNumber String number (ID), base 0.
	 * @internal
	 */
	public function _getSharedString(int $stringNumber): ?string
	{
		$stringNumber++;	// Base 1

		$xpath = $this->getXPathFromPath(self::SHARED_STRINGS_PATH);
		$ts = $xpath->query("/o:sst/o:si[$stringNumber][1]/o:t[1]");
		if ($ts !== false && $ts->length > 0) {
			$t = $ts[0];
			if (!($t instanceof \DOMElement)) {
				throw new XlsxFastEditorXmlException("Error querying XML shared string {$stringNumber}!");
			}
			return $t->nodeValue;
		}
		return null;
	}

	/**
	 * Read a string in the given worksheet at the given cell location,
	 * compatible with the shared string approach.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function readString(int $sheetNumber, string $cellName): ?string
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_NULL);
		return $cell === null ? null : $cell->readString();
	}

	private static function getWorksheetRelPath(int $sheetNumber): string
	{
		return "xl/worksheets/_rels/sheet{$sheetNumber}.xml.rels";
	}

	/**
	 * Access an hyperlink referenced from a cell of the specified sheet.
	 * @param string $rId Hyperlink reference.
	 * @internal
	 */
	public function _getHyperlink(int $sheetNumber, string $rId): ?string
	{
		if (!ctype_alnum($rId)) {
			throw new XlsxFastEditorInputException("Invalid internal hyperlink reference {$sheetNumber}/{$rId}!");
		}
		$xpath = $this->getXPathFromPath(self::getWorksheetRelPath($sheetNumber));
		$xpath->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
		$target = $xpath->evaluate(<<<xpath
			normalize-space(/pr:Relationships/pr:Relationship[@Id='{$rId}'
			and @Type='http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink'][1]/@Target)
		xpath);
		return is_string($target) ? $target : null;
	}

	/**
	 * Read a hyperlink in the given worksheet at the given cell location.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function readHyperlink(int $sheetNumber, string $cellName): ?string
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_NULL);
		return $cell === null ? null : $cell->readHyperlink();
	}

	/**
	 * Change an hyperlink associated to the given cell of the given worksheet.
	 * @return bool True if any hyperlink was cleared, false otherwise.
	 * @internal
	 */
	public function _setHyperlink(int $sheetNumber, string $rId, string $value): bool
	{
		if (!ctype_alnum($rId)) {
			throw new XlsxFastEditorInputException("Invalid internal hyperlink reference {$sheetNumber}/{$rId}!");
		}
		$xmlPath = self::getWorksheetRelPath($sheetNumber);
		$xpath = $this->getXPathFromPath($xmlPath);
		$xpath->registerNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
		$hyperlinks = $xpath->query(<<<xpath
			/pr:Relationships/pr:Relationship[@Id='{$rId}'
			and @Type='http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink'][1]
		xpath);
		if ($hyperlinks !== false && $hyperlinks->length > 0) {
			$hyperlink = $hyperlinks[0];
			if (!($hyperlink instanceof \DOMElement)) {
				throw new XlsxFastEditorXmlException("Error querying XML fragment for hyperlink {$sheetNumber}/{$rId}!");
			}
			$this->touchPath($xmlPath);
			return $hyperlink->setAttribute('Target', $value) !== false;
		}
		return false;
	}

	/**
	 * Replace the hyperlink of the cell, if that cell already has an hyperlink.
	 * Warning: does not support the creation of a new hyperlink.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 * @return bool True if the hyperlink could be replaced, false otherwise.
	 */
	public function writeHyperlink(int $sheetNumber, string $cellName, string $value): bool
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_NULL);
		return $cell === null ? false : $cell->writeHyperlink($value);
	}

	/**
	 * Write a formulat in the given worksheet at the given cell location, without changing the type/style of the cell.
	 * Auto-creates the cell if it does not already exists.
	 * Removes the formulas of the cell, if any.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function writeFormula(int $sheetNumber, string $cellName, string $value): void
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_AUTOCREATE);
		$cell->writeFormula($value);
	}

	/**
	 * Write a number in the given worksheet at the given cell location, without changing the type/style of the cell.
	 * Auto-creates the cell if it does not already exists.
	 * Removes the formulas of the cell, if any.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 * @param float $value
	 */
	public function writeFloat(int $sheetNumber, string $cellName, float $value): void
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_AUTOCREATE);
		$cell->writeFloat($value);
	}

	/**
	 * Write a number in the given worksheet at the given cell location, without changing the type/style of the cell.
	 * Auto-creates the cell if it does not already exists.
	 * Removes the formulas of the cell, if any.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 * @param int $value
	 */
	public function writeInt(int $sheetNumber, string $cellName, int $value): void
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_AUTOCREATE);
		$cell->writeInt($value);
	}

	/**
	 * Adds a new shared string and returns its ID.
	 * @internal
	 * @param string $value Value of the new shared string.
	 * @return int the ID of the new shared string.
	 */
	public function _makeNewSharedString(string $value): int
	{
		$dom = $this->getDomFromPath(self::SHARED_STRINGS_PATH);
		if ($dom->firstElementChild === null) {
			throw new XlsxFastEditorXmlException('Invalid shared strings!');
		}

		$si = $dom->createElement('si');
		if ($si === false) {
			throw new XlsxFastEditorXmlException('Error creating <si> in shared strings!');
		}
		$t = $dom->createElement('t', $value);
		if ($t === false) {
			throw new XlsxFastEditorXmlException('Error creating <t> in shared strings!');
		}
		$si->appendChild($t);
		$dom->firstElementChild->appendChild($si);

		$count = (int)$dom->firstElementChild->getAttribute('count');
		$dom->firstElementChild->setAttribute('count', (string)($count + 1));

		$uniqueCount = $dom->getElementsByTagName('si')->length;
		$dom->firstElementChild->setAttribute('uniqueCount', (string)$uniqueCount);

		$this->touchPath(self::SHARED_STRINGS_PATH);
		return $uniqueCount - 1;	// Base 0
	}

	/**
	 * Write a string in the given worksheet at the given cell location, without changing the type/style of the cell.
	 * Auto-creates the cell if it does not already exists.
	 * Removes the formulas of the cell, if any.
	 *
	 * @param int $sheetNumber Worksheet number (base 1)
	 * @param $cellName Cell name such as `B4`
	 */
	public function writeString(int $sheetNumber, string $cellName, string $value): void
	{
		$cell = $this->getCell($sheetNumber, $cellName, XlsxFastEditor::ACCESS_MODE_AUTOCREATE);
		$cell->writeString($value);
	}

	/**
	 * Regex search & replace text strings in all worksheets using [`preg_replace()`](https://php.net/function.preg-replace)
	 *
	 * @param string|array<string> $pattern The pattern to search for.
	 * @param string|array<string> $replacement The string or an array with strings to replace.
	 * @return int The number of replacements done.
	 */
	public function textReplace($pattern, $replacement): int
	{
		$dom = $this->getDomFromPath(self::SHARED_STRINGS_PATH);
		$elements = $dom->getElementsByTagName('t');
		$nb = 0;

		if ($elements->length > 0) {
			foreach ($elements as $element) {
				if ($element instanceof \DOMElement) {
					$text = preg_replace($pattern, $replacement, $element->textContent);
					if (is_string($text) && $element->textContent !== $text) {
						$element->textContent = $text;
						$nb++;
					}
				}
			}
		}

		if ($nb > 0) {
			$this->touchPath(self::SHARED_STRINGS_PATH);
		}
		return $nb;
	}
}
