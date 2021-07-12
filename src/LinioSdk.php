<?php

namespace Astroselling\LinioSdk;

use Astroselling\LinioSdk\Exceptions\FetchException;
use Astroselling\LinioSdk\Exceptions\FetchOrderException;
use Astroselling\LinioSdk\Exceptions\FetchProductException;
use Astroselling\LinioSdk\Models\LinioFeed;
use Linio\SellerCenter\SellerCenterSdk;
use Linio\SellerCenter\Application\Configuration as LinioConfiguration;
use Linio\SellerCenter\Contract\ProductStatus;
use Linio\SellerCenter\Model\Product\Products as LinioProducts;
use Linio\SellerCenter\Model\Product\Product as LinioProduct;
use Linio\SellerCenter\Model\Category\Category as LinioCategory;
use Linio\SellerCenter\Model\Brand\Brand as LinioBrand;
use Linio\SellerCenter\Model\Product\Image;
use Linio\SellerCenter\Model\Product\Images;
use Linio\SellerCenter\Model\Product\ProductData as LinioProductData;
use Linio\SellerCenter\Exception\ErrorResponseException;
use Linio\SellerCenter\Contract\ProductFilters;
use Linio\SellerCenter\Service\ProductManager;
use Linio\SellerCenter\Service\WebhookManager;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Message;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use DateTime;
use Exception;

class LinioSdk
{
    protected $sdk;
    protected bool $customLogCalls;
    protected string $userName;
    protected const URLS = [
        "ARG" => "https://sellercenter-api.linio.com.ar/",
        "CHL" => "https://sellercenter-api.linio.cl/",
        "COL" => "https://sellercenter-api.linio.com.co/",
        "MEX" => "https://sellercenter-api.linio.com.mx/",
        "PER" => "https://sellercenter-api.linio.com.pe/",
        "TST" => "https://sellercenter-api-staging.linio.com.co/",
        "TSP" => "https://sellercenter-api-staging.linio.com.pe/",
    ];

    public function getSdkUsername(): string
    {
        return $this->userName;
    }

    public function __construct(string $userName, string $apiKey, string $countryISO)
    {
        // $logger = new Logger('LinioLogger');
        // $logger->pushHandler(new StreamHandler(storage_path('logs/sdk-log.log'), Logger::INFO));
        // $logger->pushHandler(new StreamHandler(storage_path('logs/sdk-log-debug.log'), Logger::DEBUG));
        $logger = null;
        $client = new \GuzzleHttp\Client();
        $configuration = new LinioConfiguration($apiKey, $userName, self::URLS[$countryISO], '1.0');
        $this->sdk = new SellerCenterSdk($configuration, $client, $logger);

        $this->userName = $userName; // Used only for logging
        $this->customLogCalls = config('liniosdk.custom_log_calls');
    }

    private function exceptionFromErrorResponse(ErrorResponseException $e)
    {
        return new FetchOrderException(
            new Exception('Error response exception', $e->getCode()),
            [
                'Called From' => 'Linio get Order Items',
                'Type' => $e->getType(),
                'Action' => $e->getAction(),
                'Message' => $e->getMessage(),
                'Linio Username' => $this->getSdkUsername(),
        ]);
    }

    public function getProducts(int $limit, int $offset, string $filter = null): array
    {
        if (!$filter) {
            $filter = ProductManager::DEFAULT_FILTER;
        }
        if (!in_array($filter, ProductFilters::FILTERS)) {
            throw new FetchProductException(
                new Exception('Unknown product filter: '. $filter, 500),
                ['Called From' => 'Linio get Products',]
            );
        }
        try {
            $this->logLinioCall("getAllProducts (filter: $filter)");
            return $this->sdk->products()->getProductsFromParameters(
                null, //CreatedAfter
                null, //createdBefore
                null, //search
                $filter,
                $limit,
                $offset,
                null, // skuSellerList
                null, // updatedAfter
                null, // updatedBefore
            );
        } catch (RequestException $e) {
            throw new FetchProductException($e, [
                'Called From' => 'Linio get Products',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
    }

    public function deleteProducts(array $deleteIds): LinioFeed
    {
        try {
            $linioProducts = new LinioProducts();
            foreach ($deleteIds as $idInMkp) {
                $prod = LinioProduct::fromSku($idInMkp);
                $linioProducts->add($prod);
            }
            $this->logLinioCall('deleteProducts');
            $feedResponse = $this->sdk->products()->productRemove($linioProducts);
            $feed = $this->sdk->feeds()->getFeedStatusById($feedResponse->getRequestId());
            $linioFeed = LinioFeed::saveFromLinio($feed);
        } catch (RequestException $e) {
            throw new FetchException($e, [
                'Called From' => 'Delete Products',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
        return $linioFeed;
    }

    public function getOrders(int $limit, int $offset): array
    {
        // TODO: cambiar esto hardcodeado y optimizar las que trae
        // $after = new DateTime('-3 day');
        $after = new DateTime('-1 month');
        $sortBy = 'created_at';
        $sortDir = 'DESC';
        try {
            $this->logLinioCall('getOrdersCreatedAfter');
            return $this->sdk->orders()->getOrdersCreatedAfter($after, $limit, $offset, $sortBy, $sortDir);
        } catch (RequestException $e) {
            throw new FetchOrderException($e, [
                'Called From' => 'Linio get Orders',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
                'Linio Username' => $this->getSdkUsername(),
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
    }

    public function getMultipleOrderItems(array $orderIdList): array
    {
        try {
            if (count($orderIdList) == 0) {
                return [];
            }
            $this->logLinioCall('getMultipleOrderItems');
            return $this->sdk->orders()->getMultipleOrderItems($orderIdList);
        } catch (RequestException $e) {
            throw new FetchOrderException($e, [
                'Called From' => 'Linio get Order Items',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
    }

    public function getProductsBySku(array $skus) : array
    {
        try {
            if (count($skus) == 0) {
                return [];
            }
            $this->logLinioCall('getProductsBySellerSku');
            return $this->sdk->products()->getProductsBySellerSku($skus);
        } catch (RequestException $e) {
            throw new FetchProductException($e, [
                'Called From' => 'Linio get Products By Sku',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
    }

    public function getCategories(): array
    {
        $this->logLinioCall('getCategoryTree');
        return $this->sdk->categories()->getCategoryTree();
    }

    public function getCategoryAttributes(int $categoryId): array
    {
        $this->logLinioCall('getCategoryAttributes');
        return $this->sdk->categories()->getCategoryAttributes($categoryId);
    }

    public function getBrands(): array
    {
        $this->logLinioCall('getBrands');
        return $this->sdk->brands()->getBrands();
    }

    public function getFeeds(?int $offset = 0, ?int $limit = 10): array
    {
        $this->logLinioCall('getFeedOffsetList');
        return $this->sdk->feeds()->getFeedOffsetList($offset, $limit);
    }

    public function getQc(array $skus): array
    {
        $this->logLinioCall('getQcStatusBySkuSellerList');
        return $this->sdk->qualityControl()->getQcStatusBySkuSellerList($skus);
    }

    public function updateFeed(string $feedId): LinioFeed
    {
        try {
            $this->logLinioCall('getQc');
            $feed = $this->sdk->feeds()->getFeedStatusById($feedId);
            $linioFeed = LinioFeed::saveFromLinio($feed);
            return $linioFeed;
        } catch (RequestException $e) {
            throw new FetchException($e, [
                'Called From' => 'Update Feed',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
    }

    public function publishProducts(array $products): LinioFeed
    {
        $linioProducts = new LinioProducts();
        foreach ($products as $product) {
            $primaryCategory = LinioCategory::fromId($product['PrimaryCategory']);
            $brand = LinioBrand::fromName($product['Brand']);
            $images = new Images();
            $productData = new LinioProductData(
                $product['ConditionType'] ?? null,
                $product['PackageHeight'] ?? null,
                $product['PackageWidth'] ?? null,
                $product['PackageLength'] ?? null,
                $product['PackageWeight'] ?? null
            );
            if (isset($product['ShortDescription'])) {
                $productData->add('ShortDescription', $product['ShortDescription']);
            }
            $linioProd = LinioProduct::fromBasicData(
                $product['SellerSku'], // string
                $product['Name'], // string
                $product['Variation'], // string
                $primaryCategory, // Category
                $product['Description'], // string
                $brand, // Brand
                $product['Price'], // float
                $product['ProductId'], // string
                $product['TaxClass'], // nulo|string
                $productData, // ProductData
                $images //Images will be ignored when creating, and used when updating
            );
            $linioProd->setParentSku($product['ParentSku']);
            $linioProd->setQuantity($product['Quantity']);
            $linioProd->setAvailable($product['Quantity']);
            $linioProd->setStatus(ProductStatus::ACTIVE);
            $linioProducts->add($linioProd);
        }
        try {
            $this->logLinioCall('productCreate');
            $feedResponse = $this->sdk->products()->productCreate($linioProducts);
            $this->logLinioCall('getFeedStatusById');
            $feed = $this->sdk->feeds()->getFeedStatusById($feedResponse->getRequestId());
            $linioFeed = LinioFeed::saveFromLinio($feed);
            // TEMPORAL, PARA DESARROLLAR SIN ENVIAR LOS PRODUCTOS A LINIO
            // $linioFeed = LinioFeed::orderBy('id', 'desc')->first();
            return $linioFeed;
        } catch (RequestException $e) {
            throw new FetchException($e, [
                'Called From' => 'Create Product',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
                'Products' => $products
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
    }

    public function updateProducts(array $updateData): LinioFeed
    {
        try {
            $linioProducts = new LinioProducts();
            foreach ($updateData as $sku => $prodData) {
                $prod = LinioProduct::fromSku($sku);
                if ($prodData['stock'] !== null) {
                    $prod->setQuantity($prodData['stock']);
                }
                if ($prodData['price'] !== null) {
                    $prod->setPrice($prodData['price']);
                }
                if (isset($prodData['sale_price']) && $prodData['sale_price']) {
                    $prod->setSalePrice($prodData['sale_price']);
                }
                if (isset($prodData['sale_start']) && $prodData['sale_start']) {
                    $prod->setSaleStartDate($prodData['sale_start']);
                }
                if (isset($prodData['sale_end']) && $prodData['sale_end']) {
                    $prod->setSaleEndDate($prodData['sale_end']);
                }

                $linioProducts->add($prod);
            }
            $this->logLinioCall('productUpdate');
            $feedResponse = $this->sdk->products()->productUpdate($linioProducts);
            $feed = $this->sdk->feeds()->getFeedStatusById($feedResponse->getRequestId());
            $linioFeed = LinioFeed::saveFromLinio($feed);
        } catch (RequestException $e) {
            throw new FetchException($e, [
                'Called From' => 'Update Product',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
                'UpdateData' => $updateData
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
        return $linioFeed;
    }

    public function publishProductImages(array $images): LinioFeed
    {
        $imgSend = [];
        foreach ($images as $sku => $urls) {
            $imgSend[$sku] = [];
            foreach ($urls as $url) {
                $imgSend[$sku][] = new Image($url);
            }
        }
        try {
            $this->logLinioCall('addImage');
            $feedResponse = $this->sdk->products()->addImage($imgSend);
            $this->logLinioCall('getFeedStatusById');
            $feed = $this->sdk->feeds()->getFeedStatusById($feedResponse->getRequestId());
            $linioFeed = LinioFeed::saveFromLinio($feed);
        } catch (RequestException $e) {
            throw new FetchException($e, [
                'Called From' => 'Add Images',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
                'UpdateData' => $images
            ]);
        } catch (ErrorResponseException $e) {
            throw $this->exceptionFromErrorResponse($e);
        }
        return $linioFeed;
    }

    private function logLinioCall(string $method): void
    {
        if (!$this->customLogCalls) {
            return;
        }
        \Log::channel('plain')->debug(sprintf(
            "[%s] | Linio Call: %s",
            $this->userName,
            $method
        ));
    }

    /**
     * Devuelve el manager para gestionar los Webhooks.
     * @return WebhookManager
     */
    public function getWebHook(): WebhookManager
    {
        return $this->sdk->webhooks();
    }
    
    /**
     * Devuelve una orden.
     *
     * @param int $orderId
     * @return array
     * @throws FetchOrderException
     */
    public function getOrder(int $orderId): object
    {
        try {
            $this->logLinioCall('getOrder');
            return $this->sdk->orders()->getOrder($orderId);
        } catch (RequestException $e) {
            throw new FetchOrderException($e, [
                'Called From' => 'Linio get Order',
                'Response' => Message::toString($e->getResponse()),
                'Response Code' => $e->getResponse()->getStatusCode()
                    . " (" . $e->getResponse()->getReasonPhrase() . ")",
                'Request' => Message::toString($e->getRequest()),
                'Linio Username' => $this->getSdkUsername(),
            ]);
        } catch (ErrorResponseException $e) {
            throw new FetchOrderException(
                new Exception('Error response exception', $e->getCode()),
                [
                    'Called From' => 'Linio get Order',
                    'Type' => $e->getType(),
                    'Action' => $e->getAction(),
                    'Linio Username' => $this->getSdkUsername(),
                ]);
        }
    }
}
