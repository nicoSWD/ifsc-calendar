<?php declare(strict_types=1);

/**
 * @license  http://opensource.org/licenses/mit-license.php MIT
 * @link     https://github.com/nicoSWD
 * @author   Nicolas Oelgart <nico@oelgart.com>
 */
namespace nicoSWD\IfscCalendar\Infrastructure\Round;

use nicoSWD\IfscCalendar\Domain\Event\Exceptions\IFSCEventsScraperException;
use nicoSWD\IfscCalendar\Domain\Round\IFSCRoundProviderInterface;
use nicoSWD\IfscCalendar\Infrastructure\Schedule\PDFDownloader;
use nicoSWD\IfscCalendar\Infrastructure\Schedule\PDFScheduleProvider;
use Override;

final readonly class PDFRoundProvider implements IFSCRoundProviderInterface
{
    public function __construct(
        private PDFScheduleProvider $scheduleProvider,
        private PDFDownloader $downloader,
    ) {
    }

    /**
     * @inheritdoc
     * @throws IFSCEventsScraperException
     */
    #[Override] public function fetchRounds(object $event): array
    {
        $pdfPath = $this->downloader->downloadInfoSheet($event);

        if ($pdfPath) {
            $html = $this->convertPdfToHtml($pdfPath);
            $this->deleteTempFile($pdfPath);

            return $this->scheduleProvider->parseSchedule($html, $event->timezone->value);
        }

        return [];
    }

    /** @throws IFSCEventsScraperException */
    private function convertPdfToHtml(string $pdfPath): string
    {
        $process = $this->execPdfToHtml($pdfPath, $pipes);

        if (!is_resource($process)) {
            throw new IFSCEventsScraperException('Unable to convert PDF to HTML');
        }

        $html = stream_get_contents($pipes[1]);

        if (empty($html)) {
            throw new IFSCEventsScraperException("No HTML returned by 'pdftohtml'");
        }

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new IFSCEventsScraperException("'pdftohtml' exited with code {$exitCode}");
        }

        return $html;
    }

    private function execPdfToHtml(string $pdfPath, ?array &$pipes): mixed
    {
        $pdfPath = escapeshellarg($pdfPath);

        return proc_open(
            command: "pdftohtml -noframes -i -stdout {$pdfPath}",
            descriptor_spec: $this->getDescriptorSpec(),
            pipes: $pipes,
            cwd: '/tmp',
            env_vars: [],
        );
    }

    private function getDescriptorSpec(): array
    {
        return [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['file', '/tmp/error-output.txt', 'a'],
        ];
    }

    private function deleteTempFile(string $pdfPath): void
    {
        unlink($pdfPath);
    }
}
