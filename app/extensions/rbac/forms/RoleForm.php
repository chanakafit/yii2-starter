<?php

namespace justcoded\yii2\rbac\forms;

use justcoded\yii2\rbac\models\Item;
use justcoded\yii2\rbac\models\Role;
use yii\helpers\ArrayHelper;
use yii\rbac\Role as RbacRole;
use Yii;


class RoleForm extends ItemForm
{
	/**
	 * @var string[]
	 */
	public $childRoles;

	/**
	 * @var string[]
	 */
	public $allowPermissions;

	/**
	 * @var Role
	 */
	protected $role;

	/**
	 * @inheritdoc
	 */
	public function init()
	{
		$this->type = RbacRole::TYPE_ROLE;
		$this->role = new Role();
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	public function rules()
	{
		return  ArrayHelper::merge(parent::rules(), [
			[['childRoles', 'allowPermissions'], 'each', 'rule' => ['string']],
		]);
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	public function attributeLabels()
	{
		return array_merge(parent::attributeLabels(), [
			'childRoles' => 'Inherit Roles'
		]);
	}

	/**
	 * @inheritdoc
	 * @return array
	 */
	public function attributeHints()
	{
		return [
			'childRoles' => 'You can inherit other roles to have the same permissions as other roles. <br> 
				Allowed Permissions box will be updated with inherited permissions once you save changes.',
		];
	}

	/**
	 * @inheritdoc
	 */
	public function uniqueItemName($attribute, $params, $validator)
	{
		$permission = Role::getList();
		return ! isset($permission[$this->$attribute]);
	}

	/**
	 * Setter for $role
	 * @param Role $role
	 */
	public function setRole(Role $role)
	{
		$this->role = $role;
		$this->load((array)$role->getItem(), '');

		$children = Yii::$app->authManager->getChildRoles($this->name);
		$this->childRoles = ArrayHelper::map($children, 'name', 'name');
	}

	/**
	 * Main form process method
	 *
	 * @return bool
	 */
	public function save()
	{
		if (! $this->validate()) {
			return false;
		}

		if (! $item = $this->role->getItem()) {
			$item = Role::create($this->name, $this->description);
		}

		$item->description = $this->description;
		$updated = Yii::$app->authManager->update($item->name, $item);

		// clean relations
		Yii::$app->authManager->removeChildren($item);

		// set relations from input
		Role::addChilds($item, $this->childRoles, Role::TYPE_ROLE);
		Role::addChilds($item, $this->allowPermissions, Role::TYPE_PERMISSION);

		return $updated;
	}

	// TODO: refactor below


	/**
	 * @return array|bool
	 */
	public function getInheritPermissions()
	{
		if(empty($this->name)){
			return false;
		}

		$child = Yii::$app->authManager->getChildRoles($this->name);
		ArrayHelper::remove($child, $this->name);

		return ArrayHelper::map($child, 'name', 'name');
	}

	/**
	 * @return bool|null|string
	 */
	public function getAllowPermissions()
	{
		if ($this->name){
			$permissions = Yii::$app->authManager->getPermissionsByRole($this->name);
		}else{
			$permissions = Yii::$app->authManager->getPermissions();
		}

		$permissions_name = implode(',', array_keys($permissions));

		return $permissions_name;
	}


	/**
	 * @return string
	 */
	public function treeDennyPermissions()
	{
		$permissions = Yii::$app->authManager->getPermissions();

		if (!empty($this->name)){
			$allow_permissions =Yii::$app->authManager->getPermissionsByRole($this->name);
			foreach ($allow_permissions as $name => $item) {
				unset($permissions[$name]);
			}
		}

		return $this->treePermissions($permissions);
	}

	/**
	 * @return string
	 */
	public function treeAllowPermissions()
	{
		$permissions =Yii::$app->authManager->getPermissionsByRole($this->name);
		
		return $this->treePermissions($permissions);
	}

	/**
	 * @return string
	 */
	public function treePermissions(array $permissions)
	{
		ArrayHelper::remove($permissions, '*');

		$data = [];
		foreach ($permissions as $name => $permission){
			if (substr($name, -1) == '*'){
				ArrayHelper::remove($permissions, $name);
				$data[$name] = [];
			}
		}

		foreach ($permissions as $name => $permission) {
			foreach ($data as $parent_name => $perm) {
				$cut_name = substr($parent_name, 0,-1);

				$pattern = '/' . addcslashes($cut_name,'/') . '/';

				if (preg_match($pattern, $name)) {
					$data[$parent_name][] = $name;
					ArrayHelper::remove($permissions, $name);
				}
			}
		}

		foreach ($permissions as $name => $permission){
			ArrayHelper::remove($permissions, $name);
			$data[$name] = [];
		}

		$html = '';
		foreach ($data as $parent => $children){
			$html .= '<li class="permissions" data-name='.$parent.'>'. $parent;
			if (!empty($children)){
				$html .= '<ul>';
					foreach ($children as $child){
						$html .= "<li>$child</li>";
					}
				$html .= '</ul>';
			}
			$html .= '</li>';
		}
		return $html;
	}


	/**
	 * @return array
	 */
	public function getListInheritPermissions()
	{
		$roles = Yii::$app->authManager->getRoles();
		$array_roles = ArrayHelper::map($roles, 'name', 'name');
		ArrayHelper::remove($array_roles, $this->name);

		return $array_roles;
	}

	/**
	 * @return bool
	 */
	public function store()
	{
		if(!$new_role = Yii::$app->authManager->getRole($this->name)){
			$new_role = Yii::$app->authManager->createRole($this->name);
			$new_role->description = $this->description;

			if(!Yii::$app->authManager->add($new_role)){
				return false;
			}
		}else{
			$new_role->description = $this->description;
			Yii::$app->authManager->update($this->name, $new_role);
		}

		$new_role = Yii::$app->authManager->getRole($this->name);
		Yii::$app->authManager->removeChildren($new_role);

		if ($this->inherit_permissions){
			$this->addChildrenArray($this->inherit_permissions, ['parent' => $new_role], false);
		}

		if ($this->allow_permissions) {
			$this->permissions = explode(',', $this->allow_permissions);
			$this->addChildrenArray($this->permissions, ['parent' => $new_role]);
		}

		return true;
	}
}
