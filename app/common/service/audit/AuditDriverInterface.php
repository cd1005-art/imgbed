<?php
declare (strict_types = 1);

namespace app\common\service\audit;

/**
 * 审核驱动统一接口
 * 返回：['result' => pass|reject|review, 'score' => int, 'detail' => string]
 */
interface AuditDriverInterface
{
    /**
     * @param string $localPath 图片本地路径（驱动自取内容）
     * @param string $mime      图片 MIME
     */
    public function audit(string $localPath, string $mime): array;
}
