<?php
namespace DreamFactory\Core\Resources\System;

use DreamFactory\Core\Components\Package\Exporter;
use DreamFactory\Core\Components\Package\Importer;
use DreamFactory\Core\Contracts\ServiceResponseInterface;
use DreamFactory\Core\Utility\Packager;
use DreamFactory\Core\Utility\ResponseFactory;
use DreamFactory\Core\Utility\ResourcesWrapper;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Enums\ApiOptions;

class Package extends BaseSystemResource
{
    /** @inheritdoc */
    protected function handleGET()
    {
        $systemOnly = $this->request->getParameterAsBool('system_only');
        $fullTree = $this->request->getParameterAsBool('full_tree');
        $exporter = new Exporter(new \DreamFactory\Core\Components\Package\Package());
        $manifest = $exporter->getManifestOnly($systemOnly, $fullTree);

        return ResponseFactory::create($manifest);
    }

    /** @inheritdoc */
    protected function handlePOST()
    {
        // Get uploaded file
        $file = $this->request->getFile('files');

        // Get file from a url
        if (empty($file)) {
            $file = $this->request->input('import_url');
        }

        if (!empty($file)) {
            //Import
            $extension = strtolower(pathinfo((is_array($file)) ? array_get($file, 'name') : $file, PATHINFO_EXTENSION));
            $statusCode = ServiceResponseInterface::HTTP_OK;

            if ($extension === Packager::FILE_EXTENSION) {
                $package = new Packager($file);
                $result = $package->importAppFromPackage();
            } else {
                $password = $this->request->input('password');
                $overwrite = $this->request->getParameterAsBool('overwrite');
                $package = new \DreamFactory\Core\Components\Package\Package($file, true, $password);
                $importer = new Importer($package, $overwrite);
                $imported = $importer->import();
                $log = $importer->getLog();
                $result = ['success' => $imported, 'log' => $log];
                if (true === $imported) {
                    $statusCode = ServiceResponseInterface::HTTP_CREATED;
                }
            }

            return ResponseFactory::create($result, null, $statusCode);
        } else {
            //Export
            $manifest = $this->request->getPayloadData();
            $package = new \DreamFactory\Core\Components\Package\Package($manifest);
            $exporter = new Exporter($package);
            $url = $exporter->export();
            $public = $exporter->isPublic();
            $result = ['success' => true, 'path' => $url, 'is_public' => $public];

            return ResponseFactory::create($result);
        }
    }

    public static function getApiDocInfo($service, array $resource = [])
    {
        $serviceName = strtolower($service);
        $class = trim(strrchr(static::class, '\\'), '\\');
        $resourceName = strtolower(array_get($resource, 'name', $class));
        $pluralClass = Inflector::pluralize($class);
        $path = '/' . $serviceName . '/' . $resourceName;
        $wrapper = ResourcesWrapper::getWrapper();

        $apis = [
            $path => [
                'get'  => [
                    'tags'        => [$serviceName],
                    'summary'     => 'getManifestOnly() - Retrieves package manifest for all resources.',
                    'operationId' => 'getManifestOnly',
                    'parameters'  => [
                        [
                            'name'        => 'system_only',
                            'type'        => 'boolean',
                            'in'          => 'query',
                            'description' => 'Set true to only include system resources in manifest'
                        ],
                        [
                            'name'        => 'full_tree',
                            'type'        => 'boolean',
                            'in'          => 'query',
                            'description' => 'Set true to include full tree of file service resources'
                        ],
                        ApiOptions::documentOption(ApiOptions::FILE)
                    ],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Response',
                            'schema'      => ['$ref' => '#/definitions/' . $pluralClass . 'Response']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                    'description' => 'Get package manifest only'
                ],
                'post' => [
                    'tags'        => [$serviceName],
                    'summary'     => 'importExport' . $class . '() - Exports or Imports package file.',
                    'operationId' => 'importExport' . $class,
                    'parameters'  => [
                        [
                            'name'        => 'body',
                            'in'          => 'body',
                            'schema'      => ['$ref' => '#/definitions/' . $class . 'Response'],
                            'description' => 'Valid package manifest detailing service/resources to export'
                        ],
                        [
                            'name'        => 'import_url',
                            'in'          => 'query',
                            'type'        => 'string',
                            'description' => 'URL of the package file to import'
                        ],
                        [
                            'name'        => 'overwrite',
                            'in'          => 'query',
                            'type'        => 'boolean',
                            'description' => 'Set true to overwrite (PATCH) existing resource during import'
                        ],
                        [
                            'name'        => 'password',
                            'in'          => 'query',
                            'type'        => 'string',
                            'description' => 'Provide password here if package file is encrypted.'
                        ]
                    ],
                    'responses'   => [
                        '200'     => [
                            'description' => 'Response',
                            'schema'      => ['$ref' => '#/definitions/' . $class . 'ExportResponse']
                        ],
                        '201'     => [
                            'description' => 'Response',
                            'schema'      => ['$ref' => '#/definitions/' . $class . 'ImportResponse']
                        ],
                        'default' => [
                            'description' => 'Error',
                            'schema'      => ['$ref' => '#/definitions/Error']
                        ]
                    ],
                    'description' => 'Get package manifest only'
                ]
            ]
        ];

        $models = [
            $class . 'Response'       => [
                'type'       => 'object',
                'properties' => [
                    'version'      => [
                        'type'        => 'string',
                        'description' => 'Package version'
                    ],
                    'df_version'   => [
                        'type'        => 'string',
                        'description' => 'DreamFactory version'
                    ],
                    'secure'       => [
                        'type'        => 'boolean',
                        'description' => 'Flag to indicate whether sensitive data in package is encrypted (true) or not (false) '
                    ],
                    'description'  => [
                        'type'        => 'string',
                        'description' => 'Description of the package'
                    ],
                    'created_date' => [
                        'type'        => 'string',
                        'description' => 'Date/Time when package was created'
                    ],
                    'service'      => [
                        'type'        => 'object',
                        'description' => 'List of all package items. Example: system:{app:[1,2,3]} or s3:[dir, folder/sub-folder]'
                    ]
                ],
            ],
            $class . 'ExportResponse' => [
                'type'       => 'object',
                'properties' => [
                    'success'   => [
                        'type'        => 'boolean',
                        'description' => 'Indicates whether export was successful or not'
                    ],
                    'path'      => [
                        'type'        => 'string',
                        'description' => 'Path (URL) to exported file'
                    ],
                    'is_public' => [
                        'type'        => 'boolean',
                        'description' => 'Indicates whether the URL of exported file is publicly accessible or not.'
                    ]
                ]
            ],
            $class . 'ImportResponse' => [
                'type'       => 'object',
                'properties' => [
                    'success' => [
                        'type'        => 'boolean',
                        'description' => 'Indicates whether import was successful or not'
                    ],
                    'log'     => [
                        'type'        => 'array',
                        'description' => 'Import log',
                        'items'       => [
                            'type'        => 'array',
                            'description' => 'log level',
                            'items'       => [
                                'type' => 'string'
                            ]
                        ]
                    ]
                ]
            ],
            $pluralClass . 'Response' => [
                'type'       => 'object',
                'properties' => [
                    $wrapper => [
                        'type'        => 'array',
                        'description' => 'Array of records.',
                        'items'       => [
                            '$ref' => '#/definitions/' . $class . 'Response',
                        ],
                    ]
                ],
            ],
        ];

        return ['paths' => $apis, 'definitions' => $models];
    }
}