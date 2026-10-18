<?php

namespace App\Upload;

use App\Entity\Company;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Les logos des entreprises, dans public/uploads/logos.
 *
 * Le type est déterminé par le contenu du fichier, jamais par son nom : PNG, JPEG et WebP doivent être de vraies
 * images, un SVG est nettoyé (SvgSanitizer). Le fichier reçoit un nom aléatoire.
 */
final class LogoStorage
{
    public const int MAX_BYTES = 1024 * 1024;

    private const array RASTER = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        #[Autowire('%app.logo_dir%')] private readonly string $directory,
        private readonly SvgSanitizer $svgSanitizer,
    ) {
    }

    /** @throws InvalidLogoException avec un message à montrer à l'utilisateur */
    public function store(Company $company, UploadedFile $file): void
    {
        if (!$file->isValid() || $file->getSize() > self::MAX_BYTES) {
            throw new InvalidLogoException('Le logo doit peser moins de 1 Mo.');
        }

        $content = (string) file_get_contents($file->getPathname());
        $mime = (new \finfo(\FILEINFO_MIME_TYPE))->buffer($content);

        if (isset(self::RASTER[$mime])) {
            if (false === @getimagesizefromstring($content)) {
                throw new InvalidLogoException('Ce fichier n\'est pas une image lisible.');
            }
            $extension = self::RASTER[$mime];
        } elseif (\in_array($mime, ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain', 'text/html'], true) && str_contains($content, '<svg')) {
            $content = $this->svgSanitizer->sanitize($content) ?? throw new InvalidLogoException('Ce fichier SVG n\'est pas lisible.');
            $extension = 'svg';
        } else {
            throw new InvalidLogoException('Formats acceptés : PNG, JPEG, WebP ou SVG.');
        }

        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0775, true);
        }
        $filename = sprintf('%s.%s', bin2hex(random_bytes(12)), $extension);
        file_put_contents($this->directory.'/'.$filename, $content);

        $this->remove($company);
        $company->setLogoFilename($filename);
    }

    public function remove(Company $company): void
    {
        $filename = $company->getLogoFilename();
        // basename : un nom venu de la base ne peut pas sortir du dossier
        if (null !== $filename && is_file($path = $this->directory.'/'.basename($filename))) {
            unlink($path);
        }
        $company->setLogoFilename(null);
    }
}
