<?php
declare (strict_types = 1);

namespace app\admin\controller;

use app\admin\controller\Base;
use app\common\model\Setting;
use think\facade\Db;

/**
 * 模型设置：多模型审核链（按顺序优先，失败自动切换下一个，全部失败转人工）
 */
class ModelManage extends Base
{
    public function index()
    {
        $models = Db::name('audit_models')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();

        // 按用途分组：审核模型 / 生成模型（文生图）
        $auditModels = array_values(array_filter($models, fn($m) => ($m['purpose'] ?? 'audit') === 'audit'));
        $genModels   = array_values(array_filter($models, fn($m) => ($m['purpose'] ?? '') === 'image_gen'));
        $promptModels = array_values(array_filter($models, fn($m) => ($m['purpose'] ?? '') === 'prompt'));

        return $this->adminView('admin/model', [
            'page'        => 'model',
            'models'      => $auditModels,
            'gen_models'  => $genModels, 'prompt_models' => $promptModels,
            'settings'    => Setting::allSettings(),
        ]);
    }

    /** 新增 / 编辑模型卡片 */
    public function save()
    {
        $id = (int)$this->request->post('id', 0);
        $data = [
            'name'        => trim((string)$this->request->post('name', '')),
            'endpoint'    => trim((string)$this->request->post('endpoint', '')),
            'api_key'     => trim((string)$this->request->post('api_key', '')),
            'type'        => in_array($this->request->post('type'), ['llm', 'local_nsfw'], true) ? (string)$this->request->post('type') : 'llm',
            'purpose'     => in_array($this->request->post('purpose'), ['audit', 'image_gen', 'prompt'], true) ? (string)$this->request->post('purpose') : 'audit',
            'model_name'  => trim((string)$this->request->post('model_name', '')),
            'status'      => (int)$this->request->post('status', 1) === 1 ? 1 : 2,
            'update_time' => time(),
        ];
        if ($data['name'] === '' || $data['endpoint'] === '' || $data['model_name'] === '') {
            return json(['status' => false, 'message' => '名称/端点/模型名必填']);
        }

        if ($id > 0) {
            Db::name('audit_models')->where('id', $id)->update($data);
        } else {
            $maxSort = (int)Db::name('audit_models')->max('sort');
            $data['sort'] = $maxSort + 10;
            $data['create_time'] = time();
            Db::name('audit_models')->insert($data);
        }
        return json(['status' => true, 'message' => '已保存']);
    }

    public function delete(int $id)
    {
        Db::name('audit_models')->where('id', $id)->delete();
        return json(['status' => true, 'message' => '已删除']);
    }

    /** 上下移动（dir=up/down） */
    public function move(int $id, string $dir)
    {
        $cur = Db::name('audit_models')->where('id', $id)->find();
        if (!$cur) {
            return json(['status' => false, 'message' => '模型不存在']);
        }
        $models = Db::name('audit_models')->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $idx = null;
        foreach ($models as $i => $m) {
            if ((int)$m['id'] === $id) { $idx = $i; break; }
        }
        $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
        if ($idx === null || $swap < 0 || $swap >= count($models)) {
            return json(['status' => true, 'message' => '已到边界']);
        }
        // 交换 sort 值（重排序号保证稳定）
        $a = $models[$idx];
        $b = $models[$swap];
        Db::name('audit_models')->where('id', $a['id'])->update(['sort' => $b['sort'], 'update_time' => time()]);
        Db::name('audit_models')->where('id', $b['id'])->update(['sort' => $a['sort'], 'update_time' => time()]);
        return json(['status' => true, 'message' => '已调整顺序']);
    }

    /** 测试模型连接（真实发一张内置小图做审核） */
    public function test(int $id)
    {
        $m = Db::name('audit_models')->where('id', $id)->find();
        if (!$m) {
            return json(['status' => false, 'message' => '模型不存在']);
        }

        // 1x1 PNG 内置测试图
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $tmp = tempnam(sys_get_temp_dir(), 'mt_');
        file_put_contents($tmp, $png);

        $start = microtime(true);

        // 生成模型（文生图）：走 OpenAI 兼容 /images/generations
        if (($m['purpose'] ?? 'audit') === 'image_gen') {
            @unlink($tmp); // 生图不需要测试图
            $url = rtrim((string)$m['endpoint'], '/');
            if (!str_contains($url, '/images/generations')) {
                $url .= '/images/generations';
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . (string)$m['api_key'],
                ],
                CURLOPT_POSTFIELDS => json_encode([
                    'model' => (string)$m['model_name'], 'prompt' => 'a simple red circle on white background',
                    'n' => 1, 'size' => '512x512',
                ], JSON_UNESCAPED_UNICODE),
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            $ms = (int)round((microtime(true) - $start) * 1000);

            $data = json_decode((string)$raw, true);
            $hasImg = !empty($data['data'][0]['url']) || !empty($data['data'][0]['b64_json']);
            if ($raw !== false && $err === '' && $code < 400 && $hasImg) {
                return json(['status' => true, 'message' => "生图接口正常（{$ms}ms）· 已返回图片", 'data' => ['ok' => true, 'ms' => $ms]]);
            }
            $detail = mb_substr((string)($data['error']['message'] ?? ($err !== '' ? $err : ('HTTP ' . $code . ' ' . substr((string)$raw, 0, 100)))), 0, 120);
            return json(['status' => true, 'message' => "生图接口失败：{$detail}", 'data' => ['ok' => false, 'ms' => $ms]]);
        }

        try {
            $driver = ($m['type'] ?? 'llm') === 'local_nsfw'
                ? new \app\common\service\audit\LocalNsfwDriver(['endpoint' => (string)$m['endpoint']])
                : new \app\common\service\audit\LlmVlmDriver([
                    'endpoint' => (string)$m['endpoint'],
                    'key'      => (string)$m['api_key'],
                    'model'    => (string)$m['model_name'],
                ]);
            $result = $driver->audit($tmp, 'image/png');
        } finally {
            @unlink($tmp);
        }
        $ms = (int)round((microtime(true) - $start) * 1000);

        $ok = !str_starts_with((string)($result['detail'] ?? ''), 'LLM 调用失败');
        $detail = mb_substr((string)($result['detail'] ?? ''), 0, 120);
        return json([
            'status'  => true,
            'data'    => ['ok' => $ok, 'result' => $result['result'], 'score' => $result['score'], 'ms' => $ms, 'detail' => $detail],
            'message' => $ok
                ? "连接正常（{$ms}ms）· 判定 {$result['result']} · {$detail}"
                : "连接失败：{$detail}",
        ]);
    }

    public function toggle(int $id)
    {
        $m = Db::name('audit_models')->where('id', $id)->find();
        if (!$m) {
            return json(['status' => false, 'message' => '模型不存在']);
        }
        Db::name('audit_models')->where('id', $id)->update([
            'status'      => (int)$m['status'] === 1 ? 2 : 1,
            'update_time' => time(),
        ]);
        return json(['status' => true, 'message' => '已切换状态']);
    }

    /** 审核开关 + 协同模式（审核开启 = 使用模型卡片链） */
    public function driverSave()
    {
        $enabled = (int)$this->request->post('audit_enabled', 0) === 1;
        Setting::saveKey('default_audit_driver', $enabled ? 'llm_vlm' : 'none');

        $mode = (string)$this->request->post('audit_mode', 'failover');
        Setting::saveKey('audit_mode', in_array($mode, ['failover', 'parallel'], true) ? $mode : 'failover');

        return json(['status' => true, 'message' => $enabled ? '已开启审核（模型卡片链）' : '已关闭审核']);
    }
}
