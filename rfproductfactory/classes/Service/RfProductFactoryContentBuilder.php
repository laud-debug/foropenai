<?php

class RfProductFactoryContentBuilder
{
    public function build(array $data)
    {
        $description = isset($data['description']) ? trim($data['description']) : '';
        $name = isset($data['name']) ? trim($data['name']) : '';

        $paragraphs = array();
        if ($description !== '') {
            $chunks = preg_split('/(?<=[\.!?])\s+(?=[A-ZÀ-ÖØ-Þ])/u', $description);
            $buffer = '';
            foreach ($chunks as $chunk) {
                if (Tools::strlen($buffer . ' ' . $chunk) > 700 && $buffer !== '') {
                    $paragraphs[] = trim($buffer);
                    $buffer = '';
                }
                $buffer .= ($buffer === '' ? '' : ' ') . trim($chunk);
            }
            if ($buffer !== '') {
                $paragraphs[] = trim($buffer);
            }
        }

        $html = '<h2>Présentation de ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</h2>';
        if (!$paragraphs) {
            $html .= '<p>Les informations détaillées de ce produit restent à compléter avant sa mise en ligne.</p>';
        } else {
            foreach ($paragraphs as $paragraph) {
                $html .= '<p>' . htmlspecialchars($paragraph, ENT_QUOTES, 'UTF-8') . '</p>';
            }
        }

        $short = $description;
        if (Tools::strlen($short) > 350) {
            $short = rtrim(Tools::substr($short, 0, 349)) . '…';
        }

        return array(
            'description' => $html,
            'description_short' => '<p>' . htmlspecialchars($short, ENT_QUOTES, 'UTF-8') . '</p>',
            'meta_title' => $this->truncate($name, 70),
            'meta_description' => $this->truncate($description, 155),
            'link_rewrite' => Tools::link_rewrite($name),
        );
    }

    private function truncate($value, $length)
    {
        if (Tools::strlen($value) <= $length) {
            return $value;
        }
        return rtrim(Tools::substr($value, 0, $length - 1)) . '…';
    }
}
