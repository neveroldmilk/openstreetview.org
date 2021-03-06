<?php

namespace Gigablah\Silex\OAuth\EventListener;

use Gigablah\Silex\OAuth\OAuthServiceRegistry;
use Gigablah\Silex\OAuth\OAuthEvents;
use Gigablah\Silex\OAuth\Event\FilterTokenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;

/**
 * Listener to retrieve user information from the OAuth service provider.
 *
 * @author Chris Heng <bigblah@gmail.com>
 */
class UserInfoListener implements EventSubscriberInterface
{
    private $registry;
    private $config;

    /**
     * Constructor.
     *
     * @param OAuthServiceRegistry $registry
     * @param array                $config
     */
    public function __construct(OAuthServiceRegistry $registry, array $config = array())
    {
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * When the security token is created, populate it with user information from the service API.
     *
     * @param FilterTokenEvent $event
     */
    public function onFilterToken(FilterTokenEvent $event)
    {
        $token = $event->getToken();
        $service = $token->getService();
        $oauthService = $this->registry->getService($service);
        $config = $this->config[$service];

        $serviceName = OAuthServiceRegistry::getServiceName($oauthService);
        $accessToken = $oauthService->getStorage()->retrieveAccessToken($serviceName);
        $token->setAccessToken($accessToken);

        $callback = isset($config['user_callback']) && is_callable($config['user_callback']) ? $config['user_callback'] : array($this, 'defaultUserCallback');

	if (isset($config['user_endpoint'])) {
		/*###############################################################################
		 * Telenav:
		 * 
		 * This was changed because OpenStreetMap oauth service has no json response format.
		 * Please do not change this without makeing sure that openstreetmap authentification still works 
		 * 
		 * Old code:
		 * $rawUserInfo = json_decode($oauthService->request($config['user_endpoint']), true);
		 * 
		 * Replaced  with:
		 *###############################################################################
		 */
		if($serviceName == 'OpenStreetMap') {
			$xml = @simplexml_load_string($oauthService->request($config['user_endpoint']));
            if ($xml) {
    			$rawUserInfo['name'] = (string)$xml->user['display_name'];
    			$rawUserInfo['id'] = (int)$xml->user['id'];
            } else {
                throw new UnsupportedUserException("Wrong service response!");
            }
		} else  {	
			$rawUserInfo = json_decode($oauthService->request($config['user_endpoint']), true);
		}	
		/*###############################################################################
		 * Telenav:
		 * 
		 * End of change
		 *###############################################################################
		 */	
	}
	call_user_func($callback, $token, $rawUserInfo, $oauthService);
    }

    /**
     * {@inheritDoc}
     */
    public static function getSubscribedEvents()
    {
        return array(
            OAuthEvents::TOKEN => 'onFilterToken'
        );
    }

    protected function defaultUserCallback($token, $rawUserInfo, $service)
    {
        if (!is_array($rawUserInfo)) {
            return;
        }
        $serviceName = strtolower($token->getService());
        $userInfo = array();
        $fieldMap = array(
            'id' => array('id'),
            'name' => array('name', 'username', 'screen_name', 'display_name', 'login'),
            'email' => array('email')
        );

        foreach ($fieldMap as $key => $fields) {
            $userInfo[$key] = null;
            foreach ($fields as $field) {
                if (is_callable($field)) {
                    $userInfo[$key] = $field($rawUserInfo, $serviceName);
                    break;
                }
                if (isset($rawUserInfo[$field])) {
                    $userInfo[$key] = $rawUserInfo[$field];
                    break;
                }
            }
        }
        $token->setUser($userInfo['name']);
        $token->setEmail($userInfo['email']);
        $token->setUid($userInfo['id']);
    }
}
