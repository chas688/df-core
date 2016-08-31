<?php

namespace DreamFactory\Core\Resources;

use DreamFactory\Core\ADLdap\Services\ADLdap;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\UnauthorizedException;
use DreamFactory\Core\Models\App;
use DreamFactory\Core\OAuth\Services\BaseOAuthService;
use DreamFactory\Core\Utility\JWTUtilities;
use DreamFactory\Core\Utility\Session;
use ServiceManager;

class UserSessionResource extends BaseRestResource
{
    const RESOURCE_NAME = 'session';

    /**
     * Gets basic user session data and performs OAuth login redirect.
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\UnauthorizedException
     */
    protected function handleGET()
    {
        $serviceName = $this->request->getParameter('service');
        if (!empty($serviceName)) {
            /** @type BaseOAuthService $service */
            $service = ServiceManager::getService($serviceName);
            $serviceGroup = $service->getServiceTypeInfo()->getGroup();
            if (is_string($serviceGroup)) {
                if (ServiceTypeGroups::OAUTH !== $serviceGroup) {
                    throw new BadRequestException('Invalid login service provided. Please use an OAuth service.');
                }
            } elseif (is_array($serviceGroup)) {
                if (!in_array(ServiceTypeGroups::OAUTH, $serviceGroup)) {
                    throw new BadRequestException('Invalid login service provided. Please use an OAuth service.');
                }
            }

            return $service->handleLogin($this->request->getDriver());
        }

        return Session::getPublicInfo();
    }

    /**
     * Authenticates valid user.
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\UnauthorizedException
     */
    protected function handlePOST()
    {
        $serviceName = $this->getPayloadData('service');
        if (empty($serviceName)) {
            $serviceName = $this->request->getParameter('service');
        }
        if (empty($serviceName)) {
            $credentials = [
                'email'        => $this->getPayloadData('email'),
                'password'     => $this->getPayloadData('password'),
                'is_sys_admin' => false
            ];

            return $this->handleLogin($credentials, boolval($this->getPayloadData('remember_me')));
        }

        $service = ServiceManager::getService($serviceName);
        $serviceGroup = $service->getServiceTypeInfo()->getGroup();
        if (is_string($serviceGroup)) {
            switch ($serviceGroup) {
                case ServiceTypeGroups::LDAP:
                    $credentials = [
                        'username' => $this->getPayloadData('username'),
                        'password' => $this->getPayloadData('password')
                    ];

                    /** @type ADLdap $service */
                    return $service->handleLogin($credentials, $this->getPayloadData('remember_me'));
                case ServiceTypeGroups::OAUTH:
                    $oauthCallback = $this->request->getParameterAsBool('oauth_callback');

                    /** @type BaseOAuthService $service */
                    if (!empty($oauthCallback)) {
                        return $service->handleOAuthCallback();
                    } else {
                        return $service->handleLogin($this->request->getDriver());
                    }
                default:
                    throw new BadRequestException('Invalid login service provided. Please use an OAuth or AD/Ldap service.');
            }
        } elseif (is_array($serviceGroup)) {
            if (in_array(ServiceTypeGroups::LDAP, $serviceGroup)) {
                $credentials = [
                    'username' => $this->getPayloadData('username'),
                    'password' => $this->getPayloadData('password')
                ];

                /** @type ADLdap $service */
                return $service->handleLogin($credentials, $this->getPayloadData('remember_me'));
            }
            if (in_array(ServiceTypeGroups::OAUTH, $serviceGroup)) {
                $oauthCallback = $this->request->getParameterAsBool('oauth_callback');

                /** @type BaseOAuthService $service */
                if (!empty($oauthCallback)) {
                    return $service->handleOAuthCallback();
                } else {
                    return $service->handleLogin($this->request->getDriver());
                }
            }

            throw new BadRequestException('Invalid login service provided. Please use an OAuth or AD/Ldap service.');
        }
    }

    /**
     * Refreshes current JWT.
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\UnauthorizedException
     */
    protected function handlePUT()
    {
        JWTUtilities::refreshToken();

        return Session::getPublicInfo();
    }

    /**
     * Logs out user
     *
     * @return array
     */
    protected function handleDELETE()
    {
        Session::logout();

        //Clear everything in session.
        Session::flush();

        return ['success' => true];
    }

    /**
     * Performs login.
     *
     * @param array $credentials
     * @param bool  $remember
     *
     * @return array
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws UnauthorizedException
     * @throws \Exception
     */
    protected function handleLogin(array $credentials = [], $remember = false)
    {
        $email = array_get($credentials, 'email');
        if (empty($email)) {
            throw new BadRequestException('Login request is missing required email.');
        }

        $password = array_get($credentials, 'password');
        if (empty($password)) {
            throw new BadRequestException('Login request is missing required password.');
        }

        $credentials['is_active'] = 1;

        // if user management not available then only system admins can login.
        if (!class_exists('\DreamFactory\Core\User\Resources\System\User')) {
            $credentials['is_sys_admin'] = 1;
        }

        if (Session::authenticate($credentials, $remember, true, $this->getAppId())) {
            return Session::getPublicInfo();
        } else {
            throw new UnauthorizedException('Invalid credentials supplied.');
        }
    }

    /**
     * @return int|null
     */
    protected function getAppId()
    {
        //Check for API key in request parameters.
        $apiKey = $this->request->getApiKey();

        if (!empty($apiKey)) {
            return App::getAppIdByApiKey($apiKey);
        }

        return null;
    }
}