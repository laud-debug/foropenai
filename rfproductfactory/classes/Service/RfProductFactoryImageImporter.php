<?php

class RfProductFactoryImageImporter
{
    private $httpClient;

    public function __construct(RfProductFactoryHttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    public function import($idProduct, array $imageUrls, $idShop, $legend, $sourceUrl = '')
    {
        $results = $this->emptyResult(count($imageUrls));
        $state = $this->getImportState($idProduct);

        foreach (array_slice($imageUrls, 0, 8) as $imageUrl) {
            $tmpFile = null;
            $normalizedFile = null;
            $downloadedUrl = '';

            try {
                $download = $this->downloadImage((string) $imageUrl, (string) $sourceUrl);
                $response = $download['response'];
                $downloadedUrl = $download['url'];

                $tmpFile = tempnam(_PS_TMP_IMG_DIR_, 'rfpf_');
                if (!$tmpFile || file_put_contents($tmpFile, $response['body']) === false) {
                    throw new PrestaShopException('Impossible de créer le fichier image temporaire.');
                }

                $this->importFileIntoProduct(
                    $idProduct,
                    $tmpFile,
                    $idShop,
                    $legend,
                    $state,
                    $results,
                    $normalizedFile,
                    $downloadedUrl !== '' ? $downloadedUrl : (string) $imageUrl,
                    isset($response['content_type']) ? (string) $response['content_type'] : ''
                );
            } catch (Exception $e) {
                $results['errors'][] = (string) $imageUrl . ' : ' . $e->getMessage();
            } finally {
                if ($normalizedFile && file_exists($normalizedFile)) {
                    @unlink($normalizedFile);
                }
                if ($tmpFile && file_exists($tmpFile)) {
                    @unlink($tmpFile);
                }
            }
        }

        return $this->finalizeResult($results);
    }

    /**
     * Imports images uploaded by an employee through the back-office form.
     * Each item must contain the usual PHP upload keys: name, tmp_name, size and error.
     */
    public function importUploaded($idProduct, array $uploadedFiles, $idShop, $legend)
    {
        $results = $this->emptyResult(count($uploadedFiles));
        $state = $this->getImportState($idProduct);

        foreach (array_slice($uploadedFiles, 0, 8) as $uploadedFile) {
            $normalizedFile = null;
            $displayName = isset($uploadedFile['name']) ? (string) $uploadedFile['name'] : 'image locale';

            try {
                $this->validateUploadedFile($uploadedFile);
                $this->importFileIntoProduct(
                    $idProduct,
                    (string) $uploadedFile['tmp_name'],
                    $idShop,
                    $legend,
                    $state,
                    $results,
                    $normalizedFile,
                    $displayName,
                    isset($uploadedFile['type']) ? (string) $uploadedFile['type'] : ''
                );
            } catch (Exception $e) {
                $results['errors'][] = $displayName . ' : ' . $e->getMessage();
            } finally {
                if ($normalizedFile && file_exists($normalizedFile)) {
                    @unlink($normalizedFile);
                }
            }
        }

        return $this->finalizeResult($results);
    }

    public function mergeResults(array $first, array $second)
    {
        return array(
            'requested' => (int) (isset($first['requested']) ? $first['requested'] : 0)
                + (int) (isset($second['requested']) ? $second['requested'] : 0),
            'imported' => (int) (isset($first['imported']) ? $first['imported'] : 0)
                + (int) (isset($second['imported']) ? $second['imported'] : 0),
            'errors' => array_values(array_merge(
                isset($first['errors']) && is_array($first['errors']) ? $first['errors'] : array(),
                isset($second['errors']) && is_array($second['errors']) ? $second['errors'] : array()
            )),
            'imported_urls' => array_values(array_merge(
                isset($first['imported_urls']) && is_array($first['imported_urls']) ? $first['imported_urls'] : array(),
                isset($second['imported_urls']) && is_array($second['imported_urls']) ? $second['imported_urls'] : array()
            )),
        );
    }

    private function emptyResult($requested)
    {
        return array(
            'requested' => (int) $requested,
            'imported' => 0,
            'errors' => array(),
            'imported_urls' => array(),
        );
    }

    private function finalizeResult(array $results)
    {
        if ($results['requested'] > 0 && $results['imported'] === 0 && !$results['errors']) {
            $results['errors'][] = 'Aucune image n’a été importée alors que des images étaient sélectionnées.';
        }

        return $results;
    }

    private function getImportState($idProduct)
    {
        $position = (int) Image::getHighestPosition((int) $idProduct) + 1;
        $cover = Image::getCover((int) $idProduct);

        return array(
            'position' => $position,
            'has_cover' => is_array($cover) && !empty($cover['id_image']),
        );
    }

    private function validateUploadedFile(array $file)
    {
        $error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            throw new PrestaShopException('Aucun fichier n’a été transmis.');
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new PrestaShopException($this->uploadErrorMessage($error));
        }

        $tmpName = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new PrestaShopException('Le fichier envoyé n’est pas un téléversement PHP valide.');
        }

        $size = isset($file['size']) ? (int) $file['size'] : 0;
        if ($size <= 0) {
            throw new PrestaShopException('Le fichier image est vide.');
        }
        if ($size > 8 * 1024 * 1024) {
            throw new PrestaShopException('Le fichier dépasse la limite de 8 Mo.');
        }
    }

    private function uploadErrorMessage($error)
    {
        switch ((int) $error) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return 'Le fichier dépasse la taille autorisée par le serveur.';
            case UPLOAD_ERR_PARTIAL:
                return 'Le fichier n’a été envoyé que partiellement.';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Le dossier temporaire PHP est indisponible.';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Le serveur n’a pas pu écrire le fichier temporaire.';
            case UPLOAD_ERR_EXTENSION:
                return 'Une extension PHP a interrompu le téléversement.';
            default:
                return 'Le téléversement a échoué (code ' . (int) $error . ').';
        }
    }

    private function importFileIntoProduct(
        $idProduct,
        $sourceFile,
        $idShop,
        $legend,
        array &$state,
        array &$results,
        &$normalizedFile,
        $sourceLabel,
        $contentType = ''
    ) {
        $image = null;
        $completed = false;

        try {
            $imageInfo = @getimagesize($sourceFile);
            if (!$imageInfo || !isset($imageInfo[2])) {
                $contentType = trim((string) $contentType);
                throw new PrestaShopException(
                    'Le fichier n’est pas une image compatible'
                    . ($contentType !== '' ? ' (type reçu : ' . $contentType . ')' : '')
                    . '.'
                );
            }

            $width = isset($imageInfo[0]) ? (int) $imageInfo[0] : 0;
            $height = isset($imageInfo[1]) ? (int) $imageInfo[1] : 0;
            if ($width < 1 || $height < 1 || $width > 8000 || $height > 8000 || ($width * $height) > 40000000) {
                throw new PrestaShopException('Les dimensions de l’image sont trop importantes.');
            }

            $prestashopSource = $this->normalizeSourceForPrestashop(
                $sourceFile,
                (int) $imageInfo[2],
                $width,
                $height,
                $normalizedFile
            );

            $image = new Image();
            $image->id_product = (int) $idProduct;
            $image->position = (int) $state['position'];
            $image->cover = !$state['has_cover'] && $results['imported'] === 0 ? 1 : 0;
            foreach (Language::getLanguages(false) as $language) {
                $image->legend[(int) $language['id_lang']] = Tools::substr((string) $legend, 0, 128);
            }
            if (!$image->add()) {
                throw new PrestaShopException('Impossible d’ajouter l’image à PrestaShop.');
            }
            if (!$image->associateTo(array((int) $idShop))) {
                throw new PrestaShopException('Impossible d’associer l’image à la boutique courante.');
            }

            $path = $image->getPathForCreation();
            $mainError = 0;
            if (!ImageManager::resize($prestashopSource, $path . '.jpg', null, null, 'jpg', false, $mainError)) {
                $image->delete();
                throw new PrestaShopException(
                    'Impossible de convertir l’image principale (erreur ImageManager ' . (int) $mainError . ').'
                );
            }

            if (!file_exists($path . '.jpg') || filesize($path . '.jpg') < 100) {
                $image->delete();
                throw new PrestaShopException('Le fichier image principal généré est vide ou invalide.');
            }

            foreach (ImageType::getImagesTypes('products') as $type) {
                $thumbError = 0;
                if (!ImageManager::resize(
                    $prestashopSource,
                    $path . '-' . stripslashes($type['name']) . '.jpg',
                    (int) $type['width'],
                    (int) $type['height'],
                    'jpg',
                    false,
                    $thumbError
                )) {
                    $results['errors'][] = sprintf(
                        'Miniature non créée pour le format %s (erreur %d).',
                        $type['name'],
                        (int) $thumbError
                    );
                }
            }

            Hook::exec('actionWatermark', array(
                'id_image' => (int) $image->id,
                'id_product' => (int) $idProduct,
            ));

            $completed = true;
            if ((int) $image->cover === 1) {
                $state['has_cover'] = true;
            }
            ++$state['position'];
            ++$results['imported'];
            $results['imported_urls'][] = (string) $sourceLabel;
        } catch (Exception $e) {
            if (!$completed && $image instanceof Image && Validate::isLoadedObject($image)) {
                $image->delete();
            }
            throw $e;
        }
    }

    private function downloadImage($imageUrl, $sourceUrl)
    {
        $candidates = $this->buildDownloadCandidates($imageUrl);
        $lastError = null;

        foreach ($candidates as $candidate) {
            try {
                return array(
                    'url' => $candidate,
                    'response' => $this->httpClient->getImage($candidate, 8 * 1024 * 1024, 3, $sourceUrl),
                );
            } catch (Exception $e) {
                $lastError = $e;
            }
        }

        if ($lastError instanceof Exception) {
            throw $lastError;
        }
        throw new PrestaShopException('Impossible de télécharger l’image distante.');
    }

    private function buildDownloadCandidates($imageUrl)
    {
        $imageUrl = trim((string) $imageUrl);
        $candidates = array();

        if (preg_match('#^https?://(?:www\.)?shop\.novalisgames\.com/product/image/(?:small|medium)/#i', $imageUrl)) {
            $candidates[] = preg_replace('#/product/image/(?:small|medium)/#i', '/product/image/large/', $imageUrl, 1);
        }
        $candidates[] = $imageUrl;

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * PrestaShop 1.7.8 ImageManager only decodes GIF/JPEG/PNG through GD.
     * Modern formats are explicitly converted to PNG before ImageManager::resize().
     */
    private function normalizeSourceForPrestashop($sourceFile, $imageType, $width, $height, &$normalizedFile)
    {
        $legacyTypes = array(IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF);
        if (in_array((int) $imageType, $legacyTypes, true)) {
            return $sourceFile;
        }

        $loader = null;
        if (defined('IMAGETYPE_WEBP') && (int) $imageType === (int) IMAGETYPE_WEBP) {
            if (!function_exists('imagecreatefromwebp')) {
                throw new PrestaShopException('Cette image est au format WebP, mais le support WebP de PHP/GD n’est pas activé.');
            }
            $loader = 'imagecreatefromwebp';
        } elseif (defined('IMAGETYPE_AVIF') && (int) $imageType === (int) IMAGETYPE_AVIF) {
            if (!function_exists('imagecreatefromavif')) {
                throw new PrestaShopException('Cette image est au format AVIF, mais le support AVIF de PHP/GD n’est pas activé.');
            }
            $loader = 'imagecreatefromavif';
        } else {
            throw new PrestaShopException('Le format de cette image n’est pas pris en charge.');
        }

        $source = @$loader($sourceFile);
        if (!$this->isGdImage($source)) {
            throw new PrestaShopException('PHP/GD n’a pas réussi à décoder l’image.');
        }

        $canvas = imagecreatetruecolor((int) $width, (int) $height);
        if (!$this->isGdImage($canvas)) {
            @imagedestroy($source);
            throw new PrestaShopException('Impossible de préparer la conversion de l’image.');
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 255, 255, 255, 127);
        imagefilledrectangle($canvas, 0, 0, (int) $width, (int) $height, $transparent);
        imagecopy($canvas, $source, 0, 0, 0, 0, (int) $width, (int) $height);

        $normalizedFile = tempnam(_PS_TMP_IMG_DIR_, 'rfpf_png_');
        if (!$normalizedFile || !imagepng($canvas, $normalizedFile, 6)) {
            @imagedestroy($canvas);
            @imagedestroy($source);
            throw new PrestaShopException('Impossible de convertir l’image moderne en PNG.');
        }

        @imagedestroy($canvas);
        @imagedestroy($source);

        $normalizedInfo = @getimagesize($normalizedFile);
        if (!$normalizedInfo || (int) $normalizedInfo[2] !== IMAGETYPE_PNG) {
            throw new PrestaShopException('La conversion intermédiaire de l’image a échoué.');
        }

        return $normalizedFile;
    }

    private function isGdImage($value)
    {
        if (is_resource($value)) {
            return true;
        }

        return class_exists('GdImage', false) && $value instanceof GdImage;
    }
}
