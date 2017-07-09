<?php
/**
 * Nextcloud - NextNote
 *
 * @copyright Copyright (c) 2015, Ben Curtis <ownclouddev@nosolutions.com>
 * @copyright Copyright (c) 2017, Sander Brand (brantje@gmail.com)
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\NextNote\Controller;

use OCA\NextNote\Service\NextNoteService;
use OCA\NextNote\ShareBackend\NextNoteShareBackend;
use OCA\NextNote\Utility\NotFoundJSONResponse;
use OCA\NextNote\Utility\UnauthorizedJSONResponse;
use OCA\NextNote\Utility\Utils;
use \OCP\AppFramework\ApiController;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\ILogger;
use \OCP\IRequest;
use OCP\IUserManager;
use OCP\User;


class NextNoteApiController extends ApiController {

	private $config;
	private $noteService;
	private $shareBackend;
	private $userManager;

	public function __construct($appName, IRequest $request, ILogger $logger, IConfig $config, NextNoteService $noteService, NextNoteShareBackend $shareBackend, IUserManager $userManager) {
		parent::__construct($appName, $request);
		$this->config = $config;
		$this->noteService = $noteService;
		$this->shareBackend = $shareBackend;
		$this->userManager = $userManager;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @TODO Add etag / lastmodified
	 * @param int|bool $deleted
	 * @param string|bool $group
	 * @return JSONResponse
	 */
	public function index($deleted = false, $group = false) {
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		$results = $this->noteService->findNotesFromUser($uid, $deleted, $group);
		foreach ($results as &$note) {
			if(is_array($note)){
				$note = $this->noteService->find($note['id']);
			}
			$note = $note->jsonSerialize();
			$note = $this->formatApiResponse($note);

		}
		return new JSONResponse($results);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @TODO Add etag / lastmodified
	 */
	public function get($id) {
		$result = $this->noteService->find($id);
		if (!$result) {
			return new NotFoundJSONResponse();
		}
		//@todo Check access
		$result = $result->jsonSerialize();
		return new JSONResponse($this->formatApiResponse($result));
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function create($title, $grouping, $content) {
		if($title == "" || !$title){
			return new JSONResponse(['error' => 'title is missing']);
		}
		$note = [
			'title' => $title,
			'name' => $title,
			'grouping' => $grouping,
			'note' => $content
		];
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		$result = $this->noteService->create($note, $uid)->jsonSerialize();
		return new JSONResponse($this->formatApiResponse($result));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function update($id, $title, $grouping, $content, $deleted) {
		if($title == "" || !$title){
			return new JSONResponse(['error' => 'title is missing']);
		}



		$note = [
			'id' => $id,
			'title' => $title,
			'name' => $title,
			'grouping' => $grouping,
			'note' => $content,
            'deleted' => $deleted
		];
        //@TODO for sharing add access check
		$entity = $this->noteService->find($id);
		if (!$entity) {
			return new NotFoundJSONResponse();
		}

		if (!$this->shareBackend->checkPermissions(\OCP\Constants::PERMISSION_UPDATE, $entity)) {
			return new UnauthorizedJSONResponse();
		}

		$results = $this->noteService->update($note)->jsonSerialize();
		return new JSONResponse($this->formatApiResponse($results));
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function delete($id) {
		$entity = $this->noteService->find($id);
		if (!$entity) {
			return new NotFoundJSONResponse();
		}

		if (!$this->shareBackend->checkPermissions(\OCP\Constants::PERMISSION_DELETE, $entity)) {
			return new UnauthorizedJSONResponse();
		}

		$this->noteService->delete($id);
		$result = (object) ['success' => true];
		return new JSONResponse($result);
	}

	/**
	 * @param $note array
	 * @return array
	 */
	private function formatApiResponse($note){
		$uid = \OC::$server->getUserSession()->getUser()->getUID();
		$acl = [
			'permissions' => \OCP\Constants::PERMISSION_ALL
		];
		if($uid !== $note['uid']){
			$aclRoles = \OCP\Share::getItemSharedWith('nextnote', $note['id'], 'populated_shares');
			$acl = Utils::getItemByProperty('share_with', $uid, $aclRoles);

		}
		$note['owner'] = Utils::getUserInfo($note['uid']);
		$note['permissions'] = $acl['permissions'];
		$shared_with = \OCP\Share::getUsersItemShared('nextnote', $note['id'], $note['uid']);
		foreach ($shared_with as &$u){
			$u = Utils::getUserInfo($u);
		}
		$note['shared_with'] = ($note['uid'] == $uid) ? $shared_with : [$uid];
		unset($note['uid']);
		return $note;
	}
}
