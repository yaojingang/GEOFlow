<?php

namespace App\Console\Commands;

use App\Services\Topics\TopicObservationReport;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class TopicObservationsReport extends Command
{
    protected $signature = 'topics:observations-report {input? : 观测 JSON 文件} {--template : 输出空白记录模板} {--output= : 输出到新文件}';

    protected $description = '生成专题 GEO 观测记录模板或计算有证据的离线报告';

    public function handle(TopicObservationReport $reports): int
    {
        try {
            if ($this->option('template')) {
                $data = $reports->template();
            } else {
                $path = $this->argument('input');
                if (! is_string($path) || ! is_file($path) || filesize($path) > 8 * 1024 * 1024) {
                    $this->error('请提供不超过 8 MiB 的观测 JSON 文件。');

                    return self::FAILURE;
                }
                $data = $reports->build(json_decode(file_get_contents($path), true, 64, JSON_THROW_ON_ERROR));
            }
            $bytes = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
            $output = $this->option('output');
            if ($output) {
                $handle = @fopen($output, 'x');
                if (! $handle) {
                    $this->error('输出文件已存在或目录不可写，请选择新文件。');

                    return self::FAILURE;
                }try {
                    if (fwrite($handle, $bytes) !== strlen($bytes)) {
                        throw new \RuntimeException('文件写入未完成。');
                    }
                } finally {
                    fclose($handle);
                }$this->info('已生成 '.$output);
            } else {
                $this->line($bytes);
            }

            return self::SUCCESS;
        } catch (ValidationException $error) {
            $this->error(collect($error->errors())->flatten()->implode(PHP_EOL));

            return self::FAILURE;
        } catch (\Throwable $error) {
            $this->error('观测文件无法读取：'.$error->getMessage());

            return self::FAILURE;
        }
    }
}
