<?php
declare (strict_types = 1);

namespace app\common\service\audit;

use app\common\model\Setting;
use think\facade\Db;

/**
 * 多模型审核链
 *
 * 按 audit_models.sort 顺序依次尝试（status=1 启用）：
 *  - 首个返回有效判定（pass/reject/review）的模型即采用其结果
 *  - 调用失败（网络/401/解析失败）自动切换下一个模型，并累计 fail_count
 *  - 全部模型失败 → 返回 review（人工复审）
 * audit_models 为空时回退到 settings 的单模型配置（兼容旧配置）
 */
class MultiModelAuditDriver implements AuditDriverInterface
{
    public function audit(string $localPath, string $mime): array
    {
        $models = Db::name('audit_models')
            ->where('status', 1)
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();

        // 回退：单模型 settings 配置
        if (!$models) {
            $single = new LlmVlmDriver([
                'endpoint' => (string)Setting::get('llm_audit_endpoint', ''),
                'key'      => (string)Setting::get('llm_audit_key', ''),
                'model'    => (string)Setting::get('llm_audit_model', ''),
            ]);
            return $single->audit($localPath, $mime);
        }

        $errors = [];
        foreach ($models as $m) {
            $driver = new LlmVlmDriver([
                'endpoint' => (string)$m['endpoint'],
                'key'      => (string)$m['api_key'],
                'model'    => (string)$m['model_name'],
            ]);
            $result = $driver->audit($localPath, $mime);

            // 调用失败特征（detail 以"LLM 调用失败"开头）→ 尝试下一个
            $failed = isset($result['detail']) && str_starts_with((string)$result['detail'], 'LLM 调用失败');
            if (!$failed) {
                Db::name('audit_models')->where('id', $m['id'])->update([
                    'last_ok_time' => time(),
                    'fail_count'   => 0,
                ]);
                $result['detail'] = '[' . $m['name'] . '] ' . ($result['detail'] ?? '');
                return $result;
            }

            Db::name('audit_models')->where('id', $m['id'])->inc('fail_count')->update();
            $errors[] = $m['name'] . '：' . ($result['detail'] ?? '失败');
        }

        return [
            'result' => 'review',
            'score'  => 0,
            'detail' => '全部审核模型不可用，转人工复审（' . mb_substr(implode('; ', $errors), 0, 180) . '）',
        ];
    }
}
