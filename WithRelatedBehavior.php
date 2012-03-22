<?php
/**
 * WithRelatedBehavior class file.
 *
 * @author Alexander Kochetov <creocoder@gmail.com>
 * @link https://github.com/yiiext/with-related-behavior
 */

/**
 * Allows you to save related models along with the main model.
 * All relation types are supported.
 *
 * @version 0.63
 * @package yiiext.with-related-behavior
 */
class WithRelatedBehavior extends CActiveRecordBehavior
{
	/**
	 * Save main model and all it's related models recursively.
	 * @param bool $runValidation whether to perform validation before saving the record.
	 * @param array $data attributes and relations.
	 * @param CActiveRecord $owner for internal needs.
	 * @return boolean whether the saving succeeds.
	 */
	public function save($runValidation=true,$data=null,$owner=null)
	{
		if($owner===null)
			$owner=$this->getOwner();

		$db=$owner->getDbConnection();

		if($db->getCurrentTransaction()===null)
			$transaction=$db->beginTransaction();

		try
		{
			if($data===null)
			{
				$attributes=null;
				$newData=array();
			}
			else
			{
				$attributeNames=$owner->attributeNames();
				$attributes=array_intersect($data,$attributeNames);

				if($attributes===array())
					$attributes=null;

				$newData=array_diff($data,$attributeNames);
			}

			$ownerTableSchema=$owner->getTableSchema();
			$builder=$owner->getCommandBuilder();
			$schema=$builder->getSchema();
			$relations=$owner->getMetaData()->relations;
			$queue=array();

			foreach($newData as $name=>$data)
			{
				if(!is_array($data))
				{
					$name=$data;
					$data=null;
				}

				if(!$owner->hasRelated($name))
					continue;

				$relationClass=get_class($relations[$name]);
				$relatedClass=$relations[$name]->className;

				if($relationClass===CActiveRecord::BELONGS_TO)
				{
					$related=$owner->getRelated($name);

					if(!($data===null ? $related->save($runValidation) : $this->save($runValidation,$data,$related)))
					{
						if(isset($transaction))
							$transaction->rollback();

						return false;
					}

					$relatedTableSchema=CActiveRecord::model($relatedClass)->getTableSchema();
					$fks=$relations[$name]->foreignKey;

					if(is_string($fks))
						$fks=preg_split('/\s*,\s*/',$fks,-1,PREG_SPLIT_NO_EMPTY);

					foreach($fks as $i=>$fk)
					{
						if(!is_int($i))
						{
							$pk=$fk;
							$fk=$i;
						}

						if(!isset($ownerTableSchema->columns[$fk]))
							throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
								array('{class}'=>get_class($owner),'{relation}'=>$relations[$name],'{key}'=>$fk,'{table}'=>$ownerTableSchema->name)));

						if(is_int($i))
						{
							if(isset($ownerTableSchema->foreignKeys[$fk]) && $schema->compareTableNames($relatedTableSchema->rawName,$ownerTableSchema->foreignKeys[$fk][0]))
								$pk=$ownerTableSchema->foreignKeys[$fk][1];
							else // FK constraints undefined
							{
								if(is_array($relatedTableSchema->primaryKey)) // composite PK
									$pk=$relatedTableSchema->primaryKey[$i];
								else
									$pk=$relatedTableSchema->primaryKey;
							}
						}

						$owner->$fk=$related->$pk;
					}
				}
				else
					$queue[]=array($relationClass,$relatedClass,$relations[$name]->foreignKey,$name,$data);
			}

			if(!$owner->save($runValidation,$attributes))
			{
				if(isset($transaction))
					$transaction->rollback();

				return false;
			}

			foreach($queue as $pack)
			{
				list($relationClass,$relatedClass,$fks,$name,$data)=$pack;
				$related=$owner->getRelated($name);

				switch($relationClass)
				{
					case CActiveRecord::HAS_ONE:
						$relatedTableSchema=CActiveRecord::model($relatedClass)->getTableSchema();

						if(is_string($fks))
							$fks=preg_split('/\s*,\s*/',$fks,-1,PREG_SPLIT_NO_EMPTY);

						foreach($fks as $i=>$fk)
						{
							if(!is_int($i))
							{
								$pk=$fk;
								$fk=$i;
							}

							if(!isset($relatedTableSchema->columns[$fk]))
								throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
									array('{class}'=>get_class($owner),'{relation}'=>$name,'{key}'=>$fk,'{table}'=>$relatedTableSchema->name)));

							if(is_int($i))
							{
								if(isset($relatedTableSchema->foreignKeys[$fk]) && $schema->compareTableNames($ownerTableSchema->rawName,$relatedTableSchema->foreignKeys[$fk][0]))
									$pk=$relatedTableSchema->foreignKeys[$fk][1];
								else // FK constraints undefined
								{
									if(is_array($ownerTableSchema->primaryKey)) // composite PK
										$pk=$ownerTableSchema->primaryKey[$i];
									else
										$pk=$ownerTableSchema->primaryKey;
								}
							}

							$related->$fk=$owner->$pk;
						}

						if(!($data===null ? $related->save($runValidation) : $this->save($runValidation,$data,$related)))
						{
							if(isset($transaction))
								$transaction->rollback();

							return false;
						}
					break;
					case CActiveRecord::HAS_MANY:
						$relatedTableSchema=CActiveRecord::model($relatedClass)->getTableSchema();

						if(is_string($fks))
							$fks=preg_split('/\s*,\s*/',$fks,-1,PREG_SPLIT_NO_EMPTY);

						$map=array();

						foreach($fks as $i=>$fk)
						{
							if(!is_int($i))
							{
								$pk=$fk;
								$fk=$i;
							}

							if(!isset($relatedTableSchema->columns[$fk]))
								throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
									array('{class}'=>get_class($owner),'{relation}'=>$name,'{key}'=>$fk,'{table}'=>$relatedTableSchema->name)));

							if(is_int($i))
							{
								if(isset($relatedTableSchema->foreignKeys[$fk]) && $schema->compareTableNames($ownerTableSchema->rawName,$relatedTableSchema->foreignKeys[$fk][0]))
									$pk=$relatedTableSchema->foreignKeys[$fk][1];
								else // FK constraints undefined
								{
									if(is_array($ownerTableSchema->primaryKey)) // composite PK
										$pk=$ownerTableSchema->primaryKey[$i];
									else
										$pk=$ownerTableSchema->primaryKey;
								}
							}

							$map[$pk]=$fk;
						}

						foreach($related as $model)
						{
							foreach($map as $pk=>$fk)
								$model->$fk=$owner->$pk;

							if(!($data===null ? $model->save($runValidation) : $this->save($runValidation,$data,$model)))
							{
								if(isset($transaction))
									$transaction->rollback();

								return false;
							}
						}
					break;
					case CActiveRecord::MANY_MANY:
						if(!preg_match('/^\s*(.*?)\((.*)\)\s*$/',$fks,$matches))
							throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key. The format of the foreign key must be "joinTable(fk1,fk2,...)".',
								array('{class}'=>get_class($owner),'{relation}'=>$name)));

						if(($joinTable=$schema->getTable($matches[1]))===null)
							throw new CDbException(Yii::t('yiiext','The relation "{relation}" in active record class "{class}" is not specified correctly: the join table "{joinTable}" given in the foreign key cannot be found in the database.',
								array('{class}'=>get_class($owner),'{relation}'=>$name,'{joinTable}'=>$matches[1])));

						$relatedTableSchema=CActiveRecord::model($relatedClass)->getTableSchema();
						$fks=preg_split('/\s*,\s*/',$matches[2],-1,PREG_SPLIT_NO_EMPTY);
						$ownerMap=array();
						$relatedMap=array();
						$fkDefined=true;

						foreach($fks as $fk)
						{
							if(!isset($joinTable->columns[$fk]))
								throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an invalid foreign key "{key}". There is no such column in the table "{table}".',
									array('{class}'=>get_class($owner),'{relation}'=>$name,'{key}'=>$fk,'{table}'=>$joinTable->name)));

							if(isset($joinTable->foreignKeys[$fk]))
							{
								list($tableName,$pk)=$joinTable->foreignKeys[$fk];

								if(!isset($ownerMap[$pk]) && $schema->compareTableNames($ownerTableSchema->rawName,$tableName))
									$ownerMap[$pk]=$fk;
								else if(!isset($relatedMap[$pk]) && $schema->compareTableNames($relatedTableSchema->rawName,$tableName))
									$relatedMap[$pk]=$fk;
								else
								{
									$fkDefined=false;
									break;
								}
							}
							else
							{
								$fkDefined=false;
								break;
							}
						}

						if(!$fkDefined)
						{
							$ownerMap=array();
							$relatedMap=array();

							foreach($fks as $i=>$fk)
							{
								if($i<count($ownerTableSchema->primaryKey))
								{
									$pk=is_array($ownerTableSchema->primaryKey) ? $ownerTableSchema->primaryKey[$i] : $ownerTableSchema->primaryKey;
									$ownerMap[$pk]=$fk;
								}
								else
								{
									$j=$i-count($ownerTableSchema->primaryKey);
									$pk=is_array($relatedTableSchema->primaryKey) ? $relatedTableSchema->primaryKey[$j] : $relatedTableSchema->primaryKey;
									$relatedMap[$pk]=$fk;
								}
							}
						}

						if($ownerMap===array() && $relatedMap===array())
							throw new CDbException(Yii::t('yii','The relation "{relation}" in active record class "{class}" is specified with an incomplete foreign key. The foreign key must consist of columns referencing both joining tables.',
								array('{class}'=>get_class($owner),'{relation}'=>$name)));

						$insertAttributes=array();
						$deleteAttributes=array();

						foreach($related as $model)
						{
							$newFlag=$model->getIsNewRecord();

							if(!($data===null ? $model->save($runValidation) : $this->save($runValidation,$data,$model)))
							{
								if(isset($transaction))
									$transaction->rollback();

								return false;
							}

							$joinTableAttributes=array();

							foreach($ownerMap as $pk=>$fk)
								$joinTableAttributes[$fk]=$owner->$pk;

							foreach($relatedMap as $pk=>$fk)
								$joinTableAttributes[$fk]=$model->$pk;

							if(!$newFlag)
								$deleteAttributes[]=$joinTableAttributes;

							$insertAttributes[]=$joinTableAttributes;
						}

						if($deleteAttributes!==array())
						{
							$condition=$builder->createInCondition($joinTable,array_merge(array_values($ownerMap),array_values($relatedMap)),$deleteAttributes);
							$criteria=$builder->createCriteria($condition);
							$builder->createDeleteCommand($joinTable,$criteria)->execute();
						}

						foreach($insertAttributes as $attributes)
							$builder->createInsertCommand($joinTable,$attributes)->execute();
					break;
				}
			}

			if(isset($transaction))
				$transaction->commit();

			return true;
		}
		catch(Exception $e)
		{
			if(isset($transaction))
				$transaction->rollBack();

			throw $e;
		}
	}
}