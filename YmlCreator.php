<?php

namespace Mtt\FrontendBundle\Services;

use Doctrine\ORM\EntityManager;
use Mtt\AppBundle\Entity\City;
use Mtt\AppBundle\Entity\Product;
use Mtt\AppBundle\Entity\Repository\CategoryRepository;
use Symfony\Component\Console\Helper\ProgressBar;
use XMLWriter;

/**
 * Класс создания yml xml файла
 */
class YmlCreator
{
    /** @var EntityManager $em */
    protected $em;

    /** @var City $city */
    private $city;

    const GET_PRODUCTS_LIMIT = 1000;

    public function __construct(EntityManager $em)
    {
        $this->em = $em;
    }

    /**
     * Создать YML файл
     *
     * @return void
     */
    public function create($params = [], $settings, City $city, ProgressBar $progressBar)
    {
        $this->city = $city;

        $xml = new XMLWriter();
        $xml->openURI($params['xml_path']);
        $xml->startDocument('1.0', 'UTF-8');

        $this->element($xml, 'yml_catalog', function (XMLWriter $xml) use ($settings, $progressBar) {
            $xml->writeAttribute('date', date('Y-m-d H:i'));

            $this->createShopElement($xml, $settings, $progressBar);
        });

        $xml->endDocument();
        $xml->flush();
    }

    /**
     * Создать shop элемент
     *
     * @param XMLWriter $xml
     *
     * @return void
     */
    private function createShopElement(XMLWriter $xml, $settings, $progressBar): void
    {
        $this->element($xml, 'shop', function (XMLWriter $xml) use ($settings, $progressBar) {
            $xml->writeElement('name', $settings['name']);
            $xml->writeElement('company', $settings['company']);
            $xml->writeElement('url', $settings['url']);

            $this->createCurrenciesElement($xml);
            $this->createCategoriesElement($xml);
            $this->createOffersElement($xml, $settings['url'], $progressBar);
        });
    }

    /**
     * Создать currencies элемент
     *
     * @param XMLWriter $xml
     *
     * @return void
     */
    public function createCurrenciesElement(XMLWriter $xml): void
    {
        $this->element($xml, 'currencies', function (XMLWriter $xml) {
            $this->element($xml, 'currency', function (XMLWriter $xml) {
                $xml->writeAttribute('id', 'RUR');
                $xml->writeAttribute('rate', 1);
            });
        });
    }

    /**
     * Создать categories элемент
     *
     * @param XMLWriter $xml
     *
     * @return void
     */
    public function createCategoriesElement(XMLWriter $xml): void
    {
        $this->element($xml, 'categories', function (XMLWriter $xml) {
            /** @var CategoryRepository $categoryRep */
            $categoryRep = $this->em->getRepository('MttAppBundle:Category');

            $categories = $categoryRep->getAssociativeCityCategories($this->city);

            $this->elementEach($xml, 'category', $categories, function (XMLWriter $xml, string $name, int $id) {
                $xml->writeAttribute('id', $id);
                $xml->text($name);
            });
        });

    }

    /**
     * Создать offers элемент
     *
     * @param XMLWriter $xml
     *
     * @return void
     */
    public function createOffersElement(XMLWriter $xml, $url, $progressBar): void
    {
        $this->element($xml, 'offers', function (XMLWriter $xml) use ($url, $progressBar) {
            $attributes = $this->getGroupedParamsAttributes();

            foreach ($this->getAllProducts() as $products) {
                $this->getOfferElement($xml, $products, $url, $attributes, $progressBar);
            }
        });
    }

    /**
     * @param XMLWriter $xml
     * @param $products
     * @param $url
     * @return void
     */
    function getOfferElement(XMLWriter $xml, $products, $url, $attributes, $progressBar): void
    {
        $this->elementEach($xml, 'offer', $products, function (XMLWriter $xml, array $product) use ($url, $progressBar, $attributes) {

            $sluggable = $product['sectionSluggable'] ? $product['sectionSluggable'] . '/' . $product['categorySluggable'] : $product['categorySluggable'];

            $xml->writeAttribute('id', $product['id']);
            $xml->writeElement('name', $product['categoryName'] . ' ( ' . $product['name'] . ' )');
            $xml->writeElement('price', $product['price']);
            $xml->writeElement('currencyId', 'RUR');
            $xml->writeElement('categoryId', $product['category_id']);
            $xml->writeElement('url', 'https://' . $url . '/' . $sluggable);
            $xml->writeElement('picture', $product['image'] ? 'https://' . $url . '/images/' . $product['image'] : '');

            $this->element($xml, 'description', function (XMLWriter $xml) use ($product) {
                $xml->writeCdata($product['description']);
            });

            $params = $this->getOfferParams($product['attributesJson'], $attributes, $product['category_id']);

            $this->elementEach($xml, 'param', $params, function (XMLWriter $xml, $content, string $name) {
                $xml->writeAttribute('name', $name);
                $xml->text($content);
            });

            $progressBar->advance();
        });
    }

    private function getAllProducts()
    {
        $limit = self::GET_PRODUCTS_LIMIT;
        $offset = 0;
        $products = $this->getProductsWithLimitAndOffset($limit);

        while (count($products) > 0) {
            yield $products;
            $offset += $limit;
            $products = $this->getProductsWithLimitAndOffset($limit, $offset);
        }
    }

    private function getProductsWithLimitAndOffset(int $limit, int $offset = 0)
    {
        $productRep = $this->em->getRepository('MttAppBundle:Product');

        return $productRep->createQueryBuilder('p')
            ->select('p.id, p.name, p.price, p.description, p.attributes_json as attributesJson, c.id as category_id, c.short_name as categoryName, cs.sluggable as sectionSluggable, c.sluggable as categorySluggable, i.image_name as image')
            ->join('p.category', 'c')
            ->join('c.citys', 'city')
            ->join('c.catalogSection', 'cs')
            ->leftJoin('c.images', 'ni')
            ->leftJoin('ni.image', 'i')
            ->where('city.id = :city')
            ->setParameter('city', $this->city->getId())
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->orderBy('c.id')
            ->getQuery()
            ->getResult();
    }

    /**
     * Вспомогательный метод для задания параметров продукта
     *
     * @param string $params
     *
     * @return array
     */
    private function getOfferParams(array $params, $attributes, $categoryId): array
    {
        $result = [];

        foreach ($params as $key => $value) {
            if ($param = $this->getParamAttributeNameByCategoryId($categoryId, $attributes, $key)) {
                $result[$param] = is_array($value) ? implode($value, ', ') : $value;
            }
        }

        return array_filter($result);
    }

    /**
     * Вспомогательный метод для получения списка характеристик отсортированного по id категорий
     */
    private function getGroupedParamsAttributes()
    {
        $attributes = $this->em->getRepository('MttAppBundle:Attribute')->findAll();
        $groupedAttributes = [];

        foreach ($attributes as $attribute) {
            if ($attribute->getPriorityFilter() != 0) {
                $groupedAttributes[$attribute->getCategory()->getId()][$attribute->getKey()] = $attribute->getName();
            }
        }

        return $groupedAttributes;
    }

    /**
     * Вспомогательный метод для получения названия атрибута по id категории и ключу
     */
    private function getParamAttributeNameByCategoryId($id, $attributes, $key)
    {
        return $attributes[$id][$key] ?? '';
    }


    /**
     * Вспомогательный метод для создания дочерних элементов
     *
     * @param XMLWriter $xml
     * @param string $name
     * @param iterable $items
     * @param callable $content
     *
     * @return void
     */
    private function elementEach(XMLWriter $xml, string $name, iterable $items, callable $content): void
    {
        foreach ($items as $key => $value) {
            $xml->startElement($name);

            $content($xml, $value, $key);

            $xml->endElement();
        }
    }

    /**
     * Вспомогательный метод для создания дочернего элемента
     *
     * @param XMLWriter $xml
     * @param string $name
     * @param callable $content
     *
     * @return void
     */
    private function element(XMLWriter $xml, string $name, callable $content): void
    {
        $xml->startElement($name);

        $content($xml);

        $xml->endElement();
    }

}
