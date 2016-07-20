<?php
namespace DreamFactory\Core\Models;

use DreamFactory\Core\Components\DsnToConnectionConfig;
use DreamFactory\Core\Enums\ApiDocFormatTypes;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\System\Event;
use DreamFactory\Core\Services\Swagger;
use DreamFactory\Library\Utility\Inflector;
use ServiceManager;
use Symfony\Component\Yaml\Yaml;

/**
 * Service
 *
 * @property integer $id
 * @property string  $name
 * @property string  $label
 * @property string  $description
 * @property boolean $is_active
 * @property boolean $mutable
 * @property boolean $deletable
 * @property string  $type
 * @property array   $config
 * @property string  $created_date
 * @property string  $last_modified_date
 * @method static \Illuminate\Database\Query\Builder|Service whereId($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereName($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereLabel($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereIsActive($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereMutable($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereDeletable($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereType($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereCreatedDate($value)
 * @method static \Illuminate\Database\Query\Builder|Service whereLastModifiedDate($value)
 */
class Service extends BaseSystemModel
{
    use DsnToConnectionConfig;

    protected $table = 'service';

    protected $fillable = ['name', 'label', 'description', 'is_active', 'type', 'config'];

    protected $rules = [
        'name' => 'regex:/(^[A-Za-z0-9_\-]+$)+/'
    ];

    protected $validationMessages = [
        'regex' => 'Service name should only contain letters, numbers, underscores and dashes.'
    ];

    protected $guarded = [
        'id',
        'mutable',
        'deletable',
        'created_date',
        'last_modified_date',
        'created_by_id',
        'last_modified_by_id'
    ];

    protected $appends = ['config'];

    protected $casts = [
        'is_active' => 'boolean',
        'mutable'   => 'boolean',
        'deletable' => 'boolean',
        'id'        => 'integer'
    ];

    /**
     * @var array Extra config to pass to any config handler
     */
    protected $config;

    public function disableRelated()
    {
        // allow config
    }

    public static function boot()
    {
        parent::boot();

        static::created(
            function (Service $service){
                if (!empty($service->config)) {
                    // take the type information and get the config_handler class
                    // set the config giving the service id and new config
                    $serviceCfg = $service->getConfigHandler();
                    if (!empty($serviceCfg)) {
                        return $serviceCfg::setConfig($service->getKey(), $service->config);
                    }
                }

                return true;
            }
        );

        static::saved(
            function (Service $service){
                \Cache::forget('service:' . $service->name);
                \Cache::forget('service_id:' . $service->id);

                // Any changes to services needs to produce a new event list
                Event::clearCache();
                Swagger::flush();
                ServiceManager::purge($service->name);
            }
        );

        static::deleting(
            function (Service $service){
                // take the type information and get the config_handler class
                // set the config giving the service id and new config
                $serviceCfg = $service->getConfigHandler();
                if (!empty($serviceCfg)) {
                    return $serviceCfg::removeConfig($service->getKey());
                }

                return true;
            }
        );

        static::deleted(
            function (Service $service){
                \Cache::forget('service:' . $service->name);
                \Cache::forget('service_id:' . $service->id);

                // Any changes to services needs to produce a new event list
                Event::clearCache();
                Swagger::flush();
                ServiceManager::purge($service->name);
            }
        );
    }

    public static function create(array $attributes = [])
    {
        // if type is old sql_db or script, need to upgrade
        switch (array_get($attributes, 'type')) {
            case 'script':
                $attributes['type'] = array_get($attributes, 'config.type');
                unset($attributes['config']['type']);
                break;
            case 'sql_db':
                $type = '';
                $config = static::adaptConfig(array_get($attributes, 'config'), $type);
                $config['options'] = array_get($attributes, 'config.options', []);
                $config['attributes'] = array_get($attributes, 'config.attributes', []);
                $attributes['config'] = $config;
                $attributes['type'] = $type;
                break;
            case 'rws':
                // fancy trick to grab the base url from swagger
                if (empty(array_get($attributes, 'config.base_url')) &&
                    !empty($content = array_get($attributes, 'service_doc_by_service_id.0.content'))
                ) {
                    if (is_string($content)) {
                        $content =
                            static::storedContentToArray($content,
                                array_get($attributes, 'service_doc_by_service_id.0.format'));
                    }
                    if (is_array($content) && !empty($host = array_get($content, 'host'))) {
                        if (!empty($protocol = array_get($content, 'schemes'))) {
                            $protocol = (is_array($protocol) ? current($protocol) : $protocol);
                        } else {
                            $protocol = 'http';
                        }
                        $basePath = array_get($content, 'basePath', '');
                        $baseUrl = $protocol . '://' . $host . $basePath;
                        $attributes['config']['base_url'] = $baseUrl;
                    }
                }
                break;
        }

        return parent::create($attributes);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function serviceDocs()
    {
        return $this->hasMany(ServiceDoc::class, 'service_id', 'id');
    }

    /**
     * @param $name
     *
     * @return null
     */
    public static function getTypeByName($name)
    {
        $typeRec = static::whereName($name)->get(['type'])->first();

        return (isset($typeRec, $typeRec['type'])) ? $typeRec['type'] : null;
    }

    /**
     * @return array
     */
    public static function available()
    {
        // need to cache this possibly
        return static::lists('name')->all();
    }

    /**
     * @return mixed
     */
    public function getConfigAttribute()
    {
        // take the type information and get the config_handler class
        // set the config giving the service id and new config
        $serviceCfg = $this->getConfigHandler();
        if (!empty($serviceCfg)) {
            $this->config = $serviceCfg::getConfig($this->getKey());
        }

        return $this->config;
    }

    /**
     * @param array $val
     *
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    public function setConfigAttribute(array $val)
    {
        $this->config = $val;
        // take the type information and get the config_handler class
        // set the config giving the service id and new config
        $serviceCfg = $this->getConfigHandler();
        if (!empty($serviceCfg)) {
            if ($this->exists) {
                if ($serviceCfg::validateConfig($this->config, false)) {
                    $serviceCfg::setConfig($this->getKey(), $this->config);
                }
            } else {
                $serviceCfg::validateConfig($this->config);
            }
        } else {
            if (null !== $typeInfo = ServiceManager::getServiceType($this->type)) {
                if ($typeInfo->isSubscriptionRequired()) {
                    throw new BadRequestException("Provisioning Failed. Subscription required for this service type.");
                }
            }
        }
    }

    public static function storedContentToArray($content, $format, $service_info = [])
    {
        // replace service placeholders with value for this service instance
        if (!empty($name = data_get($service_info, 'name'))) {
            $lcName = strtolower($name);
            $ucwName = Inflector::camelize($name);
            $pluralName = Inflector::pluralize($name);
            $pluralUcwName = Inflector::pluralize($ucwName);

            $content = str_replace(
                ['{service.name}', '{service.names}', '{service.Name}', '{service.Names}'],
                [$lcName, $pluralName, $ucwName, $pluralUcwName],
                $content);
        }
        if (!empty($label = data_get($service_info, 'label'))) {
            $content = str_replace('{service.label}', $label, $content);
        }
        if (!empty($description = data_get($service_info, 'description'))) {
            $content = str_replace('{service.description}', $description, $content);
        }

        switch ($format) {
            case ApiDocFormatTypes::SWAGGER_JSON:
                $content = json_decode($content, true);
                break;
            case ApiDocFormatTypes::SWAGGER_YAML:
                $content = Yaml::parse($content);
                break;
            default:
                throw new InternalServerErrorException("Invalid API Doc Format '$format'.");
        }

        if (!empty($name)) {
            $paths = array_get($content, 'paths', []);
            // tricky here, loop through all indexes to check if all start with service name,
            // otherwise need to prepend service name to all.
            if (!empty(array_filter(array_keys($paths), function ($k) use ($name){
                $k = ltrim($k, '/');
                if (false !== strpos($k, '/')) {
                    $k = strstr($k, '/', true);
                }
                return (0 !== strcasecmp($name, $k));
            }))
            ) {
                $newPaths = [];
                foreach ($paths as $path => $pathDef) {
                    $newPath = '/' . $name . $path;
                    $newPaths[$newPath] = $pathDef;
                }
                $paths = $newPaths;
            }
            // make sure each path is tagged
            foreach ($paths as $path => &$pathDef) {
                foreach ($pathDef as $verb => &$verbDef) {
                    // If we leave the incoming tags, they get bubbled up to our service-level
                    // and possibly confuse the whole interface. Replace with our service name tag.
//                    if (!is_array($tag = array_get($verbDef, 'tags', []))) {
//                        $tag = [];
//                    }
//                    if (false === array_search($name, $tag)) {
//                        $tag[] = $name;
//                        $verbDef['tags'] = $tag;
//                    }
                    $verbDef['tags'] = [$name];
                }
            }
            $content['paths'] = $paths; // write any changes back
        }

        return $content;
    }

    /**
     * Returns service info cached by service name, or reads from db if not present.
     * Pass in a key to return a portion/index of the cached data.
     *
     * @param string      $name
     * @param null|string $key
     * @param null        $default
     *
     * @return mixed|null
     */
    public static function getCachedByName($name, $key = null, $default = null)
    {
        $cacheKey = 'service:' . $name;
        $result = \Cache::remember($cacheKey, \Config::get('df.default_cache_ttl'), function () use ($name){
            $service = static::whereName($name)->first(['id', 'name', 'label', 'description', 'is_active', 'type']);

            if (empty($service)) {
                throw new NotFoundException("Could not find a service for $name");
            }

            if (!empty($info = $service->serviceDocs()->first())) {
                $content = (isset($info)) ? $info->content : null;
                if (is_string($content)) {
                    $content = static::storedContentToArray($content, $info->format, $service);
                }
            }

            $settings = $service->toArray();
            if (isset($content)) {
                $settings['doc'] = $content;
            }

            return $settings;
        });

        if (is_null($result)) {
            return $default;
        }

        if (is_null($key)) {
            return $result;
        }

        return (isset($result[$key]) ? $result[$key] : $default);
    }

    /**
     * Returns service info cached, or reads from db if not present.
     * Pass in a key to return a portion/index of the cached data.
     *
     * @param int         $id
     * @param null|string $key
     * @param null        $default
     *
     * @return mixed|null
     */
    public static function getCachedById($id, $key = null, $default = null)
    {
        $name = static::getCachedNameById($id);

        return static::getCachedByName($name, $key, $default);
    }

    /**
     * Returns service name cached, or reads from db if not present.
     * Pass in a key to return a portion/index of the cached data.
     *
     * @param int $id
     *
     * @return string|null
     */
    public static function getCachedNameById($id)
    {
        $cacheKey = 'service_id:' . $id;

        return \Cache::remember($cacheKey, \Config::get('df.default_cache_ttl'), function () use ($id){
            $service = static::whereId($id)->first(['name']);

            if (empty($service)) {
                throw new NotFoundException("Could not find a service for id $id");
            }

            return $service->name;
        });
    }

    /**
     * Determine the handler for the extra config settings
     *
     * @return \DreamFactory\Core\Contracts\ServiceConfigHandlerInterface|null
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     */
    protected function getConfigHandler()
    {
        if (null !== $typeInfo = ServiceManager::getServiceType($this->type)) {
            // lookup related service type config model
            return $typeInfo->getConfigHandler();
        }

        return null;
    }
}