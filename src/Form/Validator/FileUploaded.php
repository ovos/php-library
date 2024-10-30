<?php
declare(strict_types=1);

namespace Ovos\Form\Validator;

use Ovos\Form\Element\File;
use Ovos\Form\Error;
use Ovos\Form\Validator;
use finfo;

use function array_search;

/**
 * FileUploaded
 *
 * @package Ovos
 * @author Marcin Gil <mg@ovos.at>
 */
class FileUploaded extends Validator
{
	/**#@+
	 * Error constants
	 */
	public const ERROR_INPUT_INVALID = 'upload_input_invalid';
	public const ERROR_NO_FILE = 'upload_no_file';
	public const ERROR_SIZE_EXCEEDED = 'upload_size_exceeded';
	public const ERROR_UNKNOWN = 'upload_unknown';
	public const ERROR_EMPTY = 'upload_empty';
	public const ERROR_FORBIDDEN_TYPE = 'upload_forbidden_type';
	/**#@-*/
	
	/**
	 * @var string[]
	 */
	protected array $_messages =
	[
		self::ERROR_INPUT_INVALID => 'Invalid input parameters.',
		self::ERROR_NO_FILE => 'No file sent.',
		self::ERROR_SIZE_EXCEEDED => 'Exceeded size limit.',
		self::ERROR_UNKNOWN => 'Unknown error.',
		self::ERROR_EMPTY => 'File is empty.',
		self::ERROR_FORBIDDEN_TYPE => 'Forbidden type.',
	];
	
	/**
	 * @param null|mixed $value
	 *
	 * @return bool
	 */
	public function isValid(mixed $value): bool
	{
		$id = $this->getElement()->getId();
		
		// @see http://php.net/manual/pl/features.file-upload.php
		if(!isset($_FILES[$id]['error']))
		{
			return $this->error(new Error(self::ERROR_INPUT_INVALID, 
				$this->getMessage(self::ERROR_INPUT_INVALID)));
		}
		
		switch($_FILES[$id]['error'])
		{
			case UPLOAD_ERR_OK:
				break;
			case UPLOAD_ERR_NO_FILE:
				return $this->error(new Error(self::ERROR_NO_FILE, 
					$this->getMessage(self::ERROR_NO_FILE)));
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return $this->error(new Error(self::ERROR_SIZE_EXCEEDED,
					$this->getMessage(self::ERROR_SIZE_EXCEEDED)));
			default:
				return $this->error(new Error(self::ERROR_UNKNOWN,
					$this->getMessage(self::ERROR_UNKNOWN)));
		}
		
		if($_FILES[$id]['size'] === 0)
		{
			return $this->error(new Error(self::ERROR_EMPTY, 
				$this->getMessage(self::ERROR_EMPTY)));
		}
		
		/** @var File $element */
		$element = $this->getElement();
		if($types = $element->getTypes())
		{
			$finfo = new finfo(FILEINFO_MIME_TYPE);
			$type = $finfo->file($_FILES[$id]['tmp_name']);
			
			if(false === $ext = array_search(
				$type,
				$types,
				true
			))
			{
				return $this->error(new Error(self::ERROR_FORBIDDEN_TYPE, 
					$this->getMessage(self::ERROR_FORBIDDEN_TYPE)));
			}
			
			$this->getElement()->ext = $ext;
			$this->getElement()->type = $types[$ext];
		}
		
		return true;
	}

	/**
	 * @param Error $error
	 * 
	 * @return bool
	 */
	public function error(Error $error): bool
	{
		$this->addError($error);
		
		return false;
	}
}
