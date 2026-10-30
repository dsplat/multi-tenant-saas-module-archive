<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Archive\Services;

/**
 * 极简 Markdown 安全渲染器。
 *
 * 先整体 HTML 转义，再只还原受控子集（标题/列表/代码/粗斜体/安全链接），
 * 因此原始 HTML、脚本、事件属性与外链注入都只会以文本形式出现。
 */
final class MarkdownSanitizer
{
    public function toHtml(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $lines = explode("\n", $markdown);

        $html = [];
        $inCode = false;
        $listType = null;

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line) === 1) {
                if ($inCode) {
                    $html[] = '</code></pre>';
                    $inCode = false;
                } else {
                    $this->closeList($html, $listType);
                    $html[] = '<pre><code>';
                    $inCode = true;
                }

                continue;
            }

            if ($inCode) {
                $html[] = $this->escape($line) . "\n";

                continue;
            }

            if (trim($line) === '') {
                $this->closeList($html, $listType);
                $html[] = '';

                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $matches) === 1) {
                $this->closeList($html, $listType);
                $level = strlen($matches[1]);
                $html[] = "<h{$level}>" . $this->inline($matches[2]) . "</h{$level}>";

                continue;
            }

            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $matches) === 1) {
                if ($listType !== 'ul') {
                    $this->closeList($html, $listType);
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . $this->inline($matches[1]) . '</li>';

                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.+)$/', $line, $matches) === 1) {
                if ($listType !== 'ol') {
                    $this->closeList($html, $listType);
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . $this->inline($matches[1]) . '</li>';

                continue;
            }

            $this->closeList($html, $listType);
            $html[] = '<p>' . $this->inline($line) . '</p>';
        }

        if ($inCode) {
            $html[] = '</code></pre>';
        }

        $this->closeList($html, $listType);

        return implode("\n", $html);
    }

    /**
     * @param  list<string>  $html
     */
    private function closeList(array &$html, ?string &$listType): void
    {
        if ($listType === null) {
            return;
        }

        $html[] = $listType === 'ul' ? '</ul>' : '</ol>';
        $listType = null;
    }

    private function inline(string $text): string
    {
        $escaped = $this->escape($text);

        // 代码片段先占位，避免其内容被粗斜体/链接规则二次处理
        $code = [];
        $escaped = (string) preg_replace_callback('/`([^`]+)`/', function (array $matches) use (&$code): string {
            $index = count($code);
            $code[] = '<code>' . $matches[1] . '</code>';

            return "\x00CODE{$index}\x00";
        }, $escaped);

        $escaped = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/(^|[^*])\*([^*]+)\*/', '$1<em>$2</em>', $escaped);

        // 只允许 http(s) 链接；URL 已经过 HTML 转义，属性内无法逃逸
        $escaped = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/',
            fn (array $matches): string => '<a href="' . $matches[2] . '" target="_blank" rel="noopener noreferrer nofollow">' . $matches[1] . '</a>',
            $escaped,
        );

        foreach ($code as $index => $snippet) {
            $escaped = str_replace("\x00CODE{$index}\x00", $snippet, $escaped);
        }

        return $escaped;
    }

    private function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
