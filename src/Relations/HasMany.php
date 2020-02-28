<?php
/**
 * 一对多
 * User: Siam
 * Date: 2020/2/27
 * Time: 11:21
 */

namespace EasySwoole\ORM\Relations;


use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\AbstractModel;
use EasySwoole\ORM\Exception\Exception;
use EasySwoole\ORM\Utility\FieldHandle;

class HasMany
{
    private $fatherModel;
    private $childModelName;

    public function __construct(AbstractModel $model, $class)
    {
        $this->fatherModel = $model;
        $this->childModelName = $class;
    }

    /**
     * @param $where
     * @param $pk
     * @param $joinPk
     * @param $joinType
     * @return mixed
     * @throws \Throwable
     */
    public function result($where, $pk, $joinPk, $joinType)
    {
        $ref = new \ReflectionClass($this->childModelName);

        if (!$ref->isSubclassOf(AbstractModel::class)) {
            throw new Exception("relation class must be subclass of AbstractModel");
        }

        /** @var AbstractModel $ins */
        $ins = $ref->newInstance();
        $builder = new QueryBuilder();

        if ($pk === null) {
            $pk = $this->fatherModel->schemaInfo()->getPkFiledName();
        }
        if ($joinPk === null) {
            $joinPk = $ins->schemaInfo()->getPkFiledName();
        }

        $targetTable = $ins->schemaInfo()->getTable();
        $currentTable = $this->fatherModel->schemaInfo()->getTable();

        // 支持复杂的构造
        if ($where) {
            /** @var QueryBuilder $builder */
            $builder = call_user_func($where, $builder);
            $this->fatherModel->preHandleQueryBuilder($builder);
            $builder->get($targetTable, null, $builder->getField());
        } else {
            $targetTableAlias = "ES_INS";
            // 关联表字段自动别名
            $fields = FieldHandle::parserRelationFields($this->fatherModel, $ins, $targetTableAlias);

            $builder->join($targetTable." AS {$targetTableAlias}", "{$targetTableAlias}.{$joinPk} = {$currentTable}.{$pk}", $joinType)
                ->where("{$currentTable}.{$pk}", $this->fatherModel->$pk);
            $this->fatherModel->preHandleQueryBuilder($builder);
            $builder->get($currentTable, null, $fields);
        }

        $result = $this->fatherModel->query($builder);

        if ($result) {
            $return = [];
            foreach ($result as $one) {
                // 分离结果 两个数组
                $targetData = [];
                $originData = [];
                foreach ($one as $key => $value){
                    if(isset($targetTableAlias)){
                        // 如果有包含附属别名，则是targetData
                        if (strpos($key, $targetTableAlias) !==  false){
                            $trueKey = substr($key,strpos($key,$targetTableAlias."_")+ strlen($targetTableAlias) + 1);
                            $targetData[$trueKey] = $value;
                        }else{
                            $originData[$key] = $value;
                        }
                    }else{
                        // callable $where 自行处理字段
                        $targetData[$key] = $value;
                    }
                }
                $return[] = ($ref->newInstance())->data($targetData);
            }

            return $return;
        }
        return [];
    }


    /**
     * @param array $data 原始数据 进入这里的处理都是多条 all查询结果
     * @param $withName string 预查询字段名
     * @param $where
     * @param $pk
     * @param $joinPk
     * @param $joinType
     * @return array
     * @throws Exception
     * @throws \Throwable
     */
    public function preHandleWith(array $data, $withName, $where, $pk, $joinPk, $joinType)
    {
        // 如果闭包不为空，则只能执行闭包
        if ($where !== null && is_callable($where)){
            // 闭包的只能一个一个调用
            foreach ($data as $model){
                foreach ($this->fatherModel->getWith() as $with){
                    $model->$with();
                }
            }
            return $data;
        }

        // 需要先提取主键数组，select 副表 where joinPk in (pk arrays);
        // foreach 判断主键，设置值
        $pks = array_map(function ($v) use ($pk){
            return $v->$pk;
        }, $data);
        /** @var AbstractModel $insClass */
        $insClass = new $this->childModelName;
        $insData  = $insClass->where($joinPk, $pks, 'IN')->all();
        $temData  = [];
        foreach ($insData as $insK => $insV){
            $temData[$insV[$pk]][] = $insV;// 目标表中 属于A表主键的数据 放到一个二维数组中
        }
        foreach ($data as $model){
            if (isset($temData[$model[$pk]])){ // 如果在二维数组中，有属于A表模型主键的，那么就是它的子数据
                $model[$withName] = $temData[$model[$pk]];
            }
        }

        return $data;
    }
}