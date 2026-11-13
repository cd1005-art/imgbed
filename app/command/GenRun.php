<?php
declare (strict_types = 1);

namespace app\command;

use app\common\model\Setting;
use app\common\model\StrategyGroup;
use app\common\model\User;
use app\common\service\FingerprintService;
use app\common\service\UploadService;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/**
 * 造图机器人：php think gen:run
 * 到期任务 → 生成（文生图模型 / 占位图）→ 走完整上传（审核+标签+存储）→ 自动公开
 */
class GenRun extends Command
{
    protected function configure()
    {
        $this->setName('gen:run')->setDescription('执行到期的造图任务');
    }

    protected function execute(Input $input, Output $output)
    {
        \app\common\service\CronService::beat('gen', 'started');
        $now = time();
        // 单日上限
        $dailyLimit = (int)Setting::get('gen_daily_limit', 100);
        $today = (int)Db::name('gen_task_logs')->where('create_time', '>', strtotime(date('Y-m-d')))->sum('gen_count');
        if ($today >= $dailyLimit) {
            $output->writeln("已达单日生成上限 {$dailyLimit}，跳过");
            return 0;
        }

        $tasks = Db::name('gen_tasks')->where('status', 1)->where('next_run', '<=', $now)->select()->toArray();
        if (!$tasks) {
            $output->writeln('无到期任务');
            return 0;
        }

        foreach ($tasks as $task) {
            $gen = 0; $ok = 0; $fail = 0; $detail = [];
            $prompts = json_decode((string)$task['prompts'], true) ?: [];
            if (!$prompts) {
                $prompts = ['abstract gradient background', 'minimal geometric pattern'];
            }

            $user  = User::find((int)$task['target_user_id']);
            $group = $user ? StrategyGroup::find((int)$user->group_id) : null;
            if (!$user || !$group) {
                Db::name('gen_tasks')->where('id', $task['id'])->update(['status' => 0, 'fail_streak' => (int)$task['fail_streak'] + 1]);
                $detail[] = '目标用户/策略组不存在，任务暂停';
                $this->logTask((int)$task['id'], $gen, $ok, $fail, implode('; ', $detail));
                continue;
            }

            for ($i = 0; $i < max(1, (int)$task['count_per_run']); $i++) {
                $prompt = $prompts[array_rand($prompts)];
                $tmp = sys_get_temp_dir() . '/gen_' . uniqid() . '.jpg';
                $made = $this->generate((int)$task['model_id'], $prompt, $tmp);
                if (!$made) {
                    $fail++;
                    $detail[] = "生成失败：{$prompt}";
                    continue;
                }
                $gen++;

                // 去重（robot dedup）
                if ((string)Setting::get('dedup_gen_bot', '1') === '1' && $this->isDuplicate($tmp)) {
                    @unlink($tmp);
                    $detail[] = "重复丢弃：{$prompt}";
                    continue;
                }

                try {
                    $f = new \think\file\UploadedFile($tmp, 'gen_' . date('YmdHis') . '_' . $i . '.jpg', 'image/jpeg', null, true);
                    $r = (new UploadService())->upload($f, (int)$user->id, $group, '127.0.0.1', 'gen-bot');
                    $img = $r['image'];
                    Db::name('images')->where('id', $img->id)->update(['origin' => 'gen_bot']);
                    $fresh = Db::name('images')->where('id', $img->id)->find();
                    if ((int)$task['auto_public'] === 1 && $fresh && (int)$fresh['audit_status'] === 1) {
                        Db::name('images')->where('id', $img->id)->update(['is_public' => 1]);
                    }
                    $ok++;
                    $detail[] = "OK #{$img->id} {$prompt}";
                } catch (\Throwable $e) {
                    $fail++;
                    $detail[] = '上传失败：' . mb_substr($e->getMessage(), 0, 60);
                } finally {
                    @unlink($tmp);
                }
            }

            $interval = max(5, (int)$task['interval_minutes']);
            Db::name('gen_tasks')->where('id', $task['id'])->update([
                'last_run'        => $now,
                'next_run'        => $now + $interval * 60,
                'total_generated' => (int)$task['total_generated'] + $ok,
                'fail_streak'     => $fail > 0 && $ok === 0 ? (int)$task['fail_streak'] + 1 : 0,
                'update_time'     => time(),
            ]);
            // 连续失败 3 次暂停
            if ($fail > 0 && $ok === 0 && (int)$task['fail_streak'] + 1 >= 3) {
                Db::name('gen_tasks')->where('id', $task['id'])->update(['status' => 0]);
                $detail[] = '连续失败 3 次，任务已自动暂停';
            }
            $this->logTask((int)$task['id'], $gen, $ok, $fail, implode('; ', array_slice($detail, 0, 10)));
            $output->writeln("任务 #{$task['id']} {$task['name']}：生成 {$gen}，成功 {$ok}，失败 {$fail}");
        }
        \app\common\service\CronService::beat('gen', 'CLI 执行完成（详见任务日志）');
        return 0;
    }

    /** 生成图片：model_id>0 调文生图模型；否则用 GD 占位图 */
    protected function generate(int $modelId, string $prompt, string $outFile): bool
    {
        if ($modelId > 0) {
            $m = Db::name('audit_models')->where('id', $modelId)->find();
            if ($m && ($m['purpose'] ?? '') === 'image_gen') {
                $url = rtrim((string)$m['endpoint'], '/');
                if (!str_contains($url, '/images/generations')) {
                    $url .= '/images/generations';
                }
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 120,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . (string)$m['api_key']],
                    CURLOPT_POSTFIELDS => json_encode(['model' => (string)$m['model_name'], 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024']),
                ]);
                $raw = curl_exec($ch);
                curl_close($ch);
                $data = json_decode((string)$raw, true);
                $imgUrl = $data['data'][0]['url'] ?? '';
                $b64 = $data['data'][0]['b64_json'] ?? '';
                if ($b64 !== '') {
                    return file_put_contents($outFile, base64_decode($b64)) !== false;
                }
                if ($imgUrl !== '') {
                    $bin = @file_get_contents($imgUrl);
                    return $bin !== false && file_put_contents($outFile, $bin) !== false;
                }
                return false;
            }
        }
        // GD 占位图（无生成模型时的演示/测试）
        return $this->placeholder($prompt, $outFile);
    }

    protected function placeholder(string $prompt, string $outFile): bool
    {
        $w = 1024; $h = 768;
        $im = imagecreatetruecolor($w, $h);
        $c1 = [mt_rand(20, 120), mt_rand(40, 140), mt_rand(60, 160)];
        $c2 = [mt_rand(80, 200), mt_rand(60, 180), mt_rand(90, 210)];
        for ($y = 0; $y < $h; $y++) {
            $k = $y / $h;
            $col = imagecolorallocate($im,
                (int)($c1[0] + ($c2[0] - $c1[0]) * $k),
                (int)($c1[1] + ($c2[1] - $c1[1]) * $k),
                (int)($c1[2] + ($c2[2] - $c1[2]) * $k));
            imageline($im, 0, $y, $w, $y, $col);
        }
        for ($i = 0; $i < 5; $i++) {
            $col = imagecolorallocatealpha($im, mt_rand(120, 255), mt_rand(120, 255), mt_rand(120, 255), 90);
            imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(120, 420), mt_rand(120, 420), $col);
        }
        $text = mb_substr($prompt, 0, 40);
        imagestring($im, 5, 20, $h - 30, $text, imagecolorallocate($im, 255, 255, 255));
        $ok = imagejpeg($im, $outFile, 85);
        imagedestroy($im);
        return (bool)$ok;
    }

    /** 与最近图片比对去重 */
    protected function isDuplicate(string $file): bool
    {
        $sha1 = @sha1_file($file) ?: '';
        if ($sha1 !== '' && Db::name('images')->where('sha1', $sha1)->find()) {
            return true;
        }
        $ah = FingerprintService::ahash($file);
        if ($ah === '') {
            return false;
        }
        $recent = Db::name('images')->where('origin', 'gen_bot')->order('id', 'desc')->limit(50)->column('path,storage_id');
        foreach ($recent as $path => $sid) {
            // 仅本地可算
            try {
                $row = Db::name('storage_configs')->where('id', $sid)->find();
                if (!$row || $row['driver'] !== 'local') {
                    continue;
                }
                $cfg = json_decode((string)$row['config'], true) ?: [];
                $rootRel = trim(str_replace('\\', '/', (string)($cfg['root'] ?? 'storage/images')), '/');
                $local = rtrim(public_path(), '/\\') . '/' . $rootRel . '/' . ltrim((string)$path, '/');
                if (@is_file($local) && FingerprintService::distance($ah, FingerprintService::ahash($local)) <= 6) {
                    return true;
                }
            } catch (\Throwable $e) {
            }
        }
        return false;
    }

    protected function logTask(int $taskId, int $gen, int $ok, int $fail, string $detail): void
    {
        Db::name('gen_task_logs')->insert([
            'task_id' => $taskId, 'gen_count' => $gen, 'success_count' => $ok,
            'fail_count' => $fail, 'detail' => mb_substr($detail, 0, 480), 'create_time' => time(),
        ]);
    }
}
