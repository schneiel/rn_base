<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2013 Rene Nitzsche
 *  Contact: rene@system25.de
 *  All rights reserved
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 2.1 of the License, or (at your option) any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
 ***************************************************************/


define('DEFAULT_LOCAL_FIELD', '_LOCALIZED_UID');

require_once(t3lib_extMgm::extPath('rn_base') . 'class.tx_rnbase.php');

tx_rnbase::load('tx_rnbase_util_TYPO3');
if(!tx_rnbase_util_TYPO3::isTYPO60OrHigher())
	return;

/**
 * Contains utility functions for FAL
 */
class tx_rnbase_util_TSFAL {

	/**
	 * Typoscript USER function for rendering DAM images. 
	 * This is a minimal Setup:
	 * <pre>
	 * yourObject.imagecol = USER
	 * yourObject.imagecol {
	 *   userFunc=tx_rnbase_util_TSFAL->printImages
	 *   refField=imagecol
	 *   refTable=tx_yourextkey_tablename
	 *   template = EXT:rn_base/res/simplegallery.html
	 *   # media is the fal reference record
	 *   media {
	 *     # field file contains the complete image path
	 *     file = IMAGE
	 *     file.file.import.field = file
	 *   }
	 *   # Optional setting for limit
	 *   # limit = 1
	 * }
	 * </pre>
	 * There are three additional fields in media record: file, file1 and thumbnail containing the complete
	 * image path. 
	 * The output is rendered via HTML template with ListBuilder. Have a look at EXT:rn_base/res/simplegallery.html
	 * Possible Typoscript options:
	 * refField: DAM reference field of the media records (defined in TCA and used to locate the record in MM-Table)
	 * refTable: should be the tablename where the DAM record is referenced to
	 * template: Full path to HTML template file.
	 * media: Formatting options of the DAM record. Have a look at tx_dam to find all column names
	 * limit: Limits the number of medias
	 * offset: Start media output with an offset
	 * forcedIdField: force another reference column (other than UID or _LOCALIZED_UID)
	 * 
	 *
	 * @param string $content
	 * @param array $tsConf
	 * @return string
	 */
	public function printImages ($content, $tsConf) {
		$conf = $this->createConf($tsConf);
		$file = $conf->get('template');
		$file = $file ? $file : 'EXT:rn_base/res/simplegallery.html';
		$subpartName = $conf->get('subpartName');
		$subpartName = $subpartName ? $subpartName : '###DAM_IMAGES###';
		$templateCode = tx_rnbase_util_Templates::getSubpartFromFile($file, $subpartName);

		if(!$templateCode) return '<!-- NO TEMPLATE OR SUBPART '.$subpartName.' FOUND -->';

		// Is there a customized language field configured
		$langField = DEFAULT_LOCAL_FIELD;
		$locUid = $conf->getCObj()->data[$langField]; // Save original uid
		if($conf->get('forcedIdField')) {
			$langField = $conf->get('forcedIdField');
			// Copy localized UID
			$conf->getCObj()->data[DEFAULT_LOCAL_FIELD] = $conf->getCObj()->data[$langField];
		}
		// Check if there is a valid uid given.
		$parentUid = intval($conf->getCObj()->data[DEFAULT_LOCAL_FIELD] ? $conf->getCObj()->data[DEFAULT_LOCAL_FIELD] : $conf->getCObj()->data['uid']);
		if(!$parentUid) return '<!-- Invalid data record given -->';

		$medias = self::fetchFilesByTS($conf, $conf->getCObj());
//if(!empty($medias)) {
//tx_rnbase::load('tx_rnbase_util_Debug');
//tx_rnbase_util_Debug::debug($conf->get('limit'), 'class.tx_rnbase_util_TSFAL.php Line: ' . __LINE__); // TODO: remove me
//}
		$listBuilder = tx_rnbase::makeInstance('tx_rnbase_util_ListBuilder');
		$out = $listBuilder->render($medias, false, $templateCode, 'tx_rnbase_util_MediaMarker',
						'media.', 'MEDIA', $conf->getFormatter());

		// Now set the identifier
		$markerArray['###MEDIA_PARENTUID###'] = $parentUid;
		$out = tx_rnbase_util_BaseMarker::substituteMarkerArrayCached($out, $markerArray);
		return $out;
	}

	/**
	 * This method is taken from TYPO3\CMS\Frontend\ContentObject\FileContentObject.
	 * It is a good tradition in TYPO3 that code can not be re-used. TYPO3 6.x makes 
	 * no difference...
	 *
	 * @param tx_rnbase_configurations $conf
	 * @return array
	 */
	public static function fetchFilesByTS($conf, $cObj) {
		/** @var \TYPO3\CMS\Core\Resource\FileRepository $fileRepository */
		$fileRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
		$fileObjects = array();
		$pics = array();
		tx_rnbase::load('tx_rnbase_util_Strings');
		// Getting the files
		// Try DAM style
		if($conf->get('refTable')) {
			$referencesForeignTable = $conf->getCObj()->stdWrap($conf->get($confId.'refTable'), $conf->get($confId.'refTable.'));
			$referencesFieldName = $conf->getCObj()->stdWrap($conf->get($confId.'refField'), $conf->get($confId.'refField.'));
			$referencesForeignUid = $conf->getCObj()->stdWrap($conf->get($confId.'refUid'), $conf->get($confId.'refUid.'));
			$referencesForeignUid = $referencesForeignUid ? 
					$referencesForeignUid : 
					isset($cObj->data['_LOCALIZED_UID']) ? $cObj->data['_LOCALIZED_UID'] : $cObj->data['uid'];
			$pics = $fileRepository->findByRelation($referencesForeignTable, $referencesFieldName, $referencesForeignUid);
		}
		elseif (is_array($conf->get('references.'))) {
			$confId = 'references.';
			/*
			The TypoScript could look like this:# all items related to the page.media field:
			references {
			table = pages
			uid.data = page:uid
			fieldName = media
			}# or: sys_file_references with uid 27:
			references = 27
			 */
			
//			$key = 'references';
//			$referencesUid = $cObj->stdWrap($conf[$key], $conf[$key . '.']);
//			$referencesUidArray = tx_rnbase_util_Strings::intExplode(',', $referencesUid, TRUE);
//			foreach ($referencesUidArray as $referenceUid) {
//				try {
//					$this->addToArray($fileRepository->findFileReferenceByUid($referenceUid), $fileObjects);
//				} catch (\TYPO3\CMS\Core\Resource\Exception $e) {
//					/** @var \TYPO3\CMS\Core\Log\Logger $logger */
//					$logger = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\CMS\Core\Log\LogManager')->getLogger();
//					$logger->warning('The file-reference with uid  "' . $referenceUid . '" could not be found and won\'t be included in frontend output');
//				}
//			}

			// It's important that this always stays "fieldName" and not be renamed to "field" as it would otherwise collide with the stdWrap key of that name
			$referencesFieldName = $conf->getCObj()->stdWrap($conf->get($confId.'fieldName'), $conf->get($confId.'fieldName.'));
			if ($referencesFieldName) {
				$table = $cObj->getCurrentTable();
				if ($table === 'pages' && isset($cObj->data['_LOCALIZED_UID']) && intval($cObj->data['sys_language_uid']) > 0) {
					$table = 'pages_language_overlay';
				}
				$referencesForeignTable = $conf->getCObj()->stdWrap($conf->get($confId.'table'), $conf->get($confId.'table.'));
				$referencesForeignTable = $referencesForeignTable ? $referencesForeignTable : $table;

				$referencesForeignUid = $conf->getCObj()->stdWrap($conf->get($confId.'uid'), $conf->get($confId.'uid.'));
				$referencesForeignUid = $referencesForeignUid ? 
						$referencesForeignUid : 
						isset($cObj->data['_LOCALIZED_UID']) ? $cObj->data['_LOCALIZED_UID'] : $cObj->data['uid'];
				// Vermutlich kann hier auch nur ein Objekt geliefert werden...
				$pics = $fileRepository->findByRelation($referencesForeignTable, $referencesFieldName, $referencesForeignUid);
			}
		}
		// gibt es ein Limit/offset
		$offset = intval($conf->get('offset'));
		$limit = intval($conf->get('limit'));
		if(!empty($pics) && $limit) {
			$pics = array_slice($pics, $offset, $limit);
		}
		elseif(!empty($pics) && $limit) {
			$pics = array_slice($pics, $offset);
		}
		// Die Bilder sollten jetzt noch in ein 
		$fileObjects = self::convertRef2Media($pics);
		return $fileObjects;
	}
	/**
	 * 
	 * @param $pics
	 * @return array[tx_rnbase_model_media]
	 */
	protected static function convertRef2Media($pics) {
		$fileObjects = array();
		if(is_array($pics))
			foreach($pics As $pic) {
				// getProperties() liefert derzeit nicht zurück
				$fileObjects[] = tx_rnbase::makeInstance('tx_rnbase_model_media', $pic);
			}
		elseif(is_object($pics)) {
			$fileObjects[] = tx_rnbase::makeInstance('tx_rnbase_model_media', $pics);
		}
		return $fileObjects;
	}
	/**
	 * Erstellt eine Instanz von tx_rnbase_configurations
	 *
	 * @param array $conf
	 * @return tx_rnbase_configurations
	 */
	function createConf($conf) {
		$configurations = tx_rnbase::makeInstance('tx_rnbase_configurations');
		$configurations->init($conf, $this->cObj, $conf['qualifier'], $conf['qualifier']);
		return $configurations;
	}

	/**
	 * Returns the first reference of a file. Usage by typoscript:
	 * 
	 * lib.logo = IMAGE
	 * lib.logo {
	 *   file.maxH = 30
	 *   file.maxW = 30
	 *   file.treatIdAsReference = 1
	 *   file.import.cObject = USER
	 *   file.import.cObject {
	 *     userFunc=tx_rnbase_util_TSFAL->fetchFirstReference
	 *     refField=t3logo
	 *     refTable=tx_cfcleague_teams
	 *   }
	 * }
	 *
	 * @param array $conf
	 * @return array
	 */
	public function fetchFirstReference ($content, $conf) {
		$cObj = $this->cObj;

		$uid      = $cObj->data['_LOCALIZED_UID'] ? $cObj->data['_LOCALIZED_UID'] : $cObj->data['uid'];
		$refTable = ($conf['refTable'] && is_array($GLOBALS['TCA'][$conf['refTable']])) ? $conf['refTable'] : 'tt_content';
		$refField = trim($cObj->stdWrap($conf['refField'],$conf['refField.']));

		if (isset($GLOBALS['BE_USER']->workspace) && $GLOBALS['BE_USER']->workspace !== 0) {
			$workspaceRecord = t3lib_BEfunc::getWorkspaceVersionOfRecord(
				$GLOBALS['BE_USER']->workspace,
				'tt_content',
				$uid,
				'uid'
			);

			if ($workspaceRecord) {
				$uid = $workspaceRecord['uid'];
			}
		}
		$fileRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
//		if ($table === 'pages' && isset($row['_LOCALIZED_UID']) && intval($row['sys_language_uid']) > 0) {
//			$table = 'pages_language_overlay';
//		}
		$files = $fileRepository->findByRelation($refTable, $refField, $uid);

		if(!empty($files)) {
			// Die erste Referenz zurück
			return $files[0]->getUid();
		}
		return '';
	}

	/**
	 * Fetches DAM records
	 *
	 * @param string $tablename
	 * @param int $uid
	 * @param string $refField
	 * @return array
	 */
	public static function fetchFiles($tablename, $uid, $refField) {
		/** @var \TYPO3\CMS\Core\Resource\FileRepository $fileRepository */
		$fileRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
		$pics = $fileRepository->findByRelation($tablename, $refField, $uid);
		$pics = self::fetchReferences($tablename, $uid, $refField);
		$fileObjects = self::convertRef2Media($pics);
		return $fileObjects;
	}
	public static function fetchReferences($tablename, $uid, $refField) {
		/** @var \TYPO3\CMS\Core\Resource\FileRepository $fileRepository */
		$fileRepository = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance('TYPO3\\CMS\\Core\\Resource\\FileRepository');
		$refs = $fileRepository->findByRelation($tablename, $refField, $uid);
		return $refs;
	}

	/**
	 * Render thumbnails for references in backend
	 * @param $references
	 * @param $size
	 * @param $addAttr
	 */
	public static function createThumbnails($references, $sizeArr=false) {
		$ret = array();
		foreach($references As $fileRef ) {
			$thumbnail = FALSE;
			$fileObject = $fileRef->getOriginalFile();
			if ($fileObject) {
//				$imageSetup = $config['appearance']['headerThumbnail'];
				$imageSetup = array();
				unset($imageSetup['field']);
				$sizeArr = $sizeArr ? $sizeArr : array('width' => 64, 'height' => 64);
				$imageSetup = array_merge($sizeArr, $imageSetup);
				$imageUrl = $fileObject->process(\TYPO3\CMS\Core\Resource\ProcessedFile::CONTEXT_IMAGEPREVIEW, $imageSetup)->getPublicUrl(TRUE);
				$thumbnail = '<img src="' . $imageUrl . '" alt="' . htmlspecialchars($fileRef->getTitle()) . '">';
				// TODO: Das geht bestimmt besser...
			}
			if($thumbnail)
				$ret[] = $thumbnail;
		}
		return $ret;
	}

	/**
	 * Returns the TCA description for a DAM media field
	 *
	 *	$options = array(
	 *			'label' => 'Ein Bild',
	 *			'config' => array(
	 *					'maxitems' => 2,
	 *					'size' => 2,
	 *				),
	 *		)
	 *
	 * @param array $ref
	 * @param array $options	These options are merged into the resulting TCA
	 * @return array
	 */
	public static function getMediaTCA($ref, $options=array()) {
		// $options war früher ein String. Daher muss auf String getestet werden.
		$type = 'image';
		if(is_string($options))
			$type = $options;
		if(is_array($options)) {
			$type = isset($options['type']) ? $options['type'] : $type;
			unset($options['type']);
		}
		$customSettingOverride = array();
		$allowedFileExtensions = '';
		if($type == 'image') {
			$customSettingOverride = array(
				'appearance' => array(
					'createNewRelationLinkTitle' => 'LLL:EXT:cms/locallang_ttc.xlf:images.addFileReference'
				),
				// custom configuration for displaying fields in the overlay/reference table
				// to use the imageoverlayPalette instead of the basicoverlayPalette
				'foreign_types' => array(
					'0' => array(
						'showitem' => '
							--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,
							--palette--;;filePalette'
					),
					\TYPO3\CMS\Core\Resource\File::FILETYPE_TEXT => array(
						'showitem' => '
							--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,
							--palette--;;filePalette'
					),
					\TYPO3\CMS\Core\Resource\File::FILETYPE_IMAGE => array(
						'showitem' => '
							--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,
							--palette--;;filePalette'
					),
					\TYPO3\CMS\Core\Resource\File::FILETYPE_AUDIO => array(
						'showitem' => '
							--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,
							--palette--;;filePalette'
					),
					\TYPO3\CMS\Core\Resource\File::FILETYPE_VIDEO => array(
						'showitem' => '
							--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,
							--palette--;;filePalette'
					),
					\TYPO3\CMS\Core\Resource\File::FILETYPE_SOFTWARE => array(
						'showitem' => '
							--palette--;LLL:EXT:lang/locallang_tca.xlf:sys_file_reference.imageoverlayPalette;imageoverlayPalette,
							--palette--;;filePalette'
					)
				)
			);
			$allowedFileExtensions = $GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'];
		}

		$tca = array(
			'label' => 'LLL:EXT:lang/locallang_general.xml:LGL.images',
			'config' => \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::getFileFieldTCAConfig($ref, $customSettingOverride, $allowedFileExtensions)
		);
		
		if (!empty($tca) && is_array($options)) {
			foreach ($options as $key=>$option) {
				if (is_array($option)) {
					if (!isset($tca[$key])) $tca[$key] = array();
					foreach ($option as $subkey=>$suboption) $tca[$key][$subkey] = $suboption;
				}
				else $tca[$key] = $option;
			}
		}
		return $tca;
	}

	/**
	 * Add a reference to a DAM media file
	 *
	 * @param string $tableName
	 * @return int 
	 */
	public static function addReference($tableName, $fieldName, $itemId, $uid) {
		$data = array();
		$data['uid_foreign'] = $itemId;
		$data['uid_local'] = $uid;
		$data['tablenames'] = $tableName;
		$data['ident'] = $fieldName;

		$id = tx_rnbase_util_DB::doInsert('tx_dam_mm_ref',$data);
		
		// Now count all items
		self::updateImageCount($tableName, $fieldName, $itemId);
		
		return $id;
	}

	/**
	 * Removes dam references. If no parameter is given, all references will be removed.
	 *
	 * @param string $uids commaseperated uids
	 */
	public static function deleteReferences($tableName, $fieldName, $itemId, $uids = '') {

		$where = 'tablenames=\'' . $tableName . '\' AND ident=\'' . $fieldName .'\' AND uid_foreign=' . $itemId;
		if(strlen(trim($uids))) {
			$uids = implode(',',t3lib_div::intExplode(',',$uids));
			$where .= ' AND uid_local IN (' . $uids .') ';
		}
		tx_rnbase_util_DB::doDelete('tx_dam_mm_ref',$where);
		// Jetzt die Bildanzahl aktualisieren
		self::updateImageCount($tableName, $fieldName, $itemId);
	}

	/**
	 * die Bildanzahl aktualisieren
	 *
	 */
	public static function updateImageCount($tableName, $fieldName, $itemId) {
		$values = array();
		$values[$fieldName] = self::getImageCount($tableName, $fieldName, $itemId);		
		tx_rnbase_util_DB::doUpdate($tableName,'uid='.$itemId,$values,0);
	}
	/**
	 * Get picture count
	 * @return int
	 */
	public static function getImageCount($tableName, $fieldName, $itemId) {
		$options['where'] = 'tablenames=\'' . $tableName . '\' AND ident=\'' . $fieldName .'\' AND uid_foreign=' . $itemId;
		$options['count'] = 1;
		$options['enablefieldsoff'] = 1;
		$ret = tx_rnbase_util_DB::doSelect('count(*) AS \'cnt\'', 'tx_dam_mm_ref', $options, 0);
		$cnt = count($ret) ? intval($ret[0]['cnt']) : 0;
		return $cnt;
	}

	/**
	 * Return all references for the given reference data
	 * 
	 * @param string $refTable
	 * @param string $refField
	 * @return array
	 */
	public static function getReferences($refTable, $refUid, $refField) {
		require_once(t3lib_extMgm::extPath('dam') . 'lib/class.tx_dam_db.php');
		return tx_dam_db::getReferencedFiles($refTable, $refUid, $refField);
	}
	
	/**
	 * Return file info for all references for the given reference data
	 * 
	 * @param string $refTable
	 * @param string $refField
	 * @return array
	 */
	public static function getReferencesFileInfo($refTable, $refUid, $refField) {
		$refs = self::getReferences($refTable, $refUid, $refField);
		$res = array();
		if (isset($refs['rows']) && count($refs['rows'])) {
			foreach ($refs['rows'] as $uid=>$record) {
				$fileInfo = self::getFileInfo($record);
				if (isset($refs['files'][$uid]))
					$fileInfo['file_path_name'] = $refs['files'][$uid];
				$fileInfo['file_abs_url'] = t3lib_div::getIndpEnv('TYPO3_SITE_URL') . $fileInfo['file_path_name'];
				$res[$uid] = $fileInfo;
			}
		}
		return $res;
	}
	
	/**
	 * Return first reference for the given reference data
	 * 
	 * @param string $refTable
	 * @param int $refUid
	 * @param string $refField
	 * @return array
	 */
	public static function getFirstReference($refTable, $refUid, $refField) {
		$refs = self::fetchReferences($refTable, $refUid, $refField);
		return reset($refs);
	}
	public static function getFileReferenceById($uid) {
		return \TYPO3\CMS\Core\Resource\ResourceFactory::getInstance()->getFileReferenceObject($uid);
	}
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/rn_base/util/class.tx_rnbase_util_TSDAM.php']) {
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']['ext/rn_base/util/class.tx_rnbase_util_TSDAM.php']);
}

?>