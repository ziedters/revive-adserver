<?php

/*
+---------------------------------------------------------------------------+
| OpenX v${RELEASE_MAJOR_MINOR}                                                                |
| =======${RELEASE_MAJOR_MINOR_DOUBLE_UNDERLINE}                                                                |
|                                                                           |
| Copyright (c) 2003-2009 OpenX Limited                                     |
| For contact details, see: http://www.openx.org/                           |
|                                                                           |
| This program is free software; you can redistribute it and/or modify      |
| it under the terms of the GNU General Public License as published by      |
| the Free Software Foundation; either version 2 of the License, or         |
| (at your option) any later version.                                       |
|                                                                           |
| This program is distributed in the hope that it will be useful,           |
| but WITHOUT ANY WARRANTY; without even the implied warranty of            |
| MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the             |
| GNU General Public License for more details.                              |
|                                                                           |
| You should have received a copy of the GNU General Public License         |
| along with this program; if not, write to the Free Software               |
| Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA |
+---------------------------------------------------------------------------+
$Id$
*/
require_once MAX_PATH . '/lib/OA/Dal/DataGenerator.php';

/**
 * A class for testing the Publisher Console Market Plugin Client
 *
 * @package    OpenXPlugin
 * @subpackage TestSuite
 * @author     Lukasz Wikierski <lukasz.wikierski@openx.org>
 */
class Plugins_admin_oxMarket_PublisherConsoleMarketPluginClientTest extends UnitTestCase
{
    
    function setUp()
    {
        $oPkgMgr = new OX_PluginManager();
        TestEnv::uninstallPluginPackage('openXMarket',false);
        TestEnv::installPluginPackage('openXMarket',false);
        // We can mockup classes after plugin is installed 
        require_once dirname(dirname(__FILE__)) . '/util/PublisherConsoleMarketPluginTestClient.php';
        require_once dirname(dirname(__FILE__)) . '/util/PublisherConsoleTestClient.php';
        if (!class_exists('PartialMockPublisherConsoleClient'))
        {
            Mock::generatePartial(
                'Plugins_admin_oxMarket_PublisherConsoleClient',
                'PartialMockPublisherConsoleClient',
                array('createAccount',
                      'isSsoUserNameAvailable')
            );
        }
    }

    function tearDown()
    {
        TestEnv::uninstallPluginPackage('openXMarket',false);
    }

    function testCreateAccount()
    {
        $email = 'email@test.org';
        $username = 'testUsername';
        $password = 'test';
        $captcha = 'captcha';
        $captcha_random = 'captcha_random';
        
        $sso_id = 1234;

        $callArgs = array($email, $username, md5($password), $captcha, $captcha_random);
    
        // Create mockup for PubConsoleClient
        $PubConsoleClient = new PartialMockPublisherConsoleClient($this);
        $PubConsoleClient->expect('createAccount', $callArgs);
        $PubConsoleClient->setReturnValue('createAccount', $sso_id);
        
        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);

        // Try create account - when there is no admin account set
        try {
            $result = $oPCMarketPluginClient->createAccount($email, $username, $password, $captcha, $captcha_random);
            $this->fail('Should have thrown exception');
        } catch (Plugins_admin_oxMarket_PublisherConsoleClientException $e) {
            $this->assertEqual($e->getMessage(),
                                'There is no admin account id in database');
        }
        
        // Create admin account
        $doAccounts = OA_Dal::factoryDO('accounts');
        $doAccounts->account_type = OA_ACCOUNT_ADMIN;
        $adminAccountId = DataGenerator::generateOne($doAccounts);
        
        // Test valid use
        $result = $oPCMarketPluginClient->createAccount($email, $username, $password, $captcha, $captcha_random);
        
        $this->assertTrue($result);
        
        $doMarketAssoc = OA_DAL::factoryDO('ext_market_assoc_data');
        $doMarketAssoc->account_id = DataObjects_Accounts::getAdminAccountId();
        $doMarketAssoc->find();
        $this->assertTrue($doMarketAssoc->fetch());
        $this->assertEqual($result, $doMarketAssoc->publisher_account_id);
        $this->assertEqual(Plugins_admin_oxMarket_PublisherConsoleMarketPluginClient::LINK_IS_VALID_STATUS,
            $doMarketAssoc->status);
        $this->assertFalse($doMarketAssoc->fetch()); // only one entry
        
        // Try to call this method once again
        try {
            $result = $oPCMarketPluginClient->createAccount($email, $username, $password, $captcha, $captcha_random);
            $this->fail('Should have thrown exception');
        } catch (Plugins_admin_oxMarket_PublisherConsoleClientException $e) {
            $this->assertEqual($e->getMessage(),
                                'There is already publisher_account_id on the OXP');
        }
    }
    
    function testIsSsoUserNameAvailable()
    {
        $testName1 = 'ada';
        $testName2 = 'adam';

        // Create mockup for PubConsoleClient
        $PubConsoleClient = new PartialMockPublisherConsoleClient($this);
        $PubConsoleClient->expectArgumentsAt(0, 'isSsoUserNameAvailable', array($testName1));
        $PubConsoleClient->setReturnValueAt(0, 'isSsoUserNameAvailable', false);
        $PubConsoleClient->expectArgumentsAt(1, 'isSsoUserNameAvailable', array($testName2));
        $PubConsoleClient->setReturnValueAt(1, 'isSsoUserNameAvailable', true);

        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);
        
        $result = $oPCMarketPluginClient->isSsoUserNameAvailable($testName1);
        $this->assertFalse($result);
        $result = $oPCMarketPluginClient->isSsoUserNameAvailable($testName2);
        $this->assertTrue($result);
    }
    
    function testGetDictionaryData()
    {
        $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime'] = 3;
        
        // Clear var/cache
        $oCache = new OX_oxMarket_Common_Cache('DictionaryData', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache->setFileNameProtection(false);
        $oCache->clear();
        
        // Prepare test client
        $PubConsoleClient = new PublisherConsoleTestClient();
        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);
        
        // (5) Test no cache, exception from PCclient, no var/data/dictionary cached data
        // should return empty array
        $PubConsoleClient->dictionaryData = new Exception('testException1');
        $result = $oPCMarketPluginClient->getDictionaryData('DictionaryData','testGetDictionaryData');
        $this->assertEqual(array(), $result);
        
        // Cache file shouldn't be created
        $this->assertFalse($oCache->load(true));
        
        // (4) Test no cache / exception from PC client / var/data/dictionary exists
        // to get var/data/dictionray file we have to get DefaultRestrictions permament cache
        $oCache2 = new OX_oxMarket_Common_Cache('DefaultRestrictions', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache2->setFileNameProtection(false);
        $oCache2->clear();
        $PubConsoleClient->dictionaryData = new Exception('testException2');
        $result = $oPCMarketPluginClient->getDictionaryData('DefaultRestrictions','testGetDictionaryData');
        $this->assertTrue(is_array($result['attribute']));
        $this->assertTrue(is_array($result['category']));
        $this->assertTrue(is_array($result['type']));
        
        // Cache file shouldn't be created
        $this->assertFalse($oCache->load(true));
        $this->assertFalse($oCache2->load(true));
        
        // (2) Test no cache / recieved data from PC client
        $data1 = array('1' => 'test');
        $PubConsoleClient->dictionaryData = $data1;
        $result = $oPCMarketPluginClient->getDictionaryData('DictionaryData','testGetDictionaryData');
        $this->assertEqual($result, $data1);
        // cache is created and is valid
        $this->assertTrue($oCache->load(false));
        
        // (1) Test there is valid cache (from previous test case)
        $data2 = array('2' => 'test2');
        $PubConsoleClient->dictionaryData = $data2;
        $result = $oPCMarketPluginClient->getDictionaryData('DictionaryData','testGetDictionaryData');
        $this->assertEqual($result, $data1);
        
        // Prepare for next test case
        // Wait 5 seconds 
        sleep(5);
        // Check cache file, should exists but should be invalid
        $oCache = new OX_oxMarket_Common_Cache('DictionaryData', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache->setFileNameProtection(false);
        $this->assertTrue($oCache->load(true));
        $this->assertFalse($oCache->load(false));
        
        // (3) Test there is invalid cache and exception is thrown from PC client
        $PubConsoleClient->dictionaryData = new Exception('testException3');
        $result = $oPCMarketPluginClient->getDictionaryData('DictionaryData','testGetDictionaryData');
        $this->assertEqual($result, $data1);
        
        // Cache shouldn't be changed (remains invalid)
        $this->assertTrue($oCache->load(true));
        $this->assertFalse($oCache->load(false));
        
        // (2) Test invalid cache, valid response from PC client
        $data3 = array('3' => 'test3'); 
        $PubConsoleClient->dictionaryData = $data3;
        $result = $oPCMarketPluginClient->getDictionaryData('DictionaryData','testGetDictionaryData');
        $this->assertEqual($result, $data3);
        // cache is created and is valid
        $this->assertTrue($oCache->load(false));
    }
    
    function testGetDefaultRestrictions()
    {
        $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime'] = 60;

        // Clear var/cache
        $oCache = new OX_oxMarket_Common_Cache('DefaultRestrictions', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache->setFileNameProtection(false);
        $oCache->clear();
        
        // Prepare test client
        $PubConsoleClient = new PublisherConsoleTestClient();
        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);
        
        // Test bundled var/data/dictionary cache
        $PubConsoleClient->dictionaryData = new Exception('testException');
        $result = $oPCMarketPluginClient->getDefaultRestrictions();
        $this->assertTrue(is_array($result['attribute']));
        $this->assertTrue(is_array($result['category']));
        $this->assertTrue(is_array($result['type']));
        // Cache file shouldn't be created
        $this->assertFalse($oCache->load(true));
        
        // Test creating own cache file
        $data = array( '1' => 'test');
        $PubConsoleClient->dictionaryData = $data;
        $result = $oPCMarketPluginClient->getDefaultRestrictions();
        $this->assertEqual($result, $data);
        // Cache file is created
        $this->assertTrue($oCache->load(false));
        
        // clear cache
        $oCache->clear();
    }
    
    function testGetAdCategories()
    {
        $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime'] = 60;

        // Clear var/cache
        $oCache = new OX_oxMarket_Common_Cache('AdCategories', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache->setFileNameProtection(false);
        $oCache->clear();
        
        // Prepare test client
        $PubConsoleClient = new PublisherConsoleTestClient();
        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);
        
        // Test bundled var/data/dictionary cache
        $PubConsoleClient->dictionaryData = new Exception('testException');
        $result = $oPCMarketPluginClient->getAdCategories();
        $this->assertTrue(is_array($result));
        // There should be at least 30 categories
        $this->assertTrue(count($result)>=30); 
        // All categories have id and description
        foreach ($result as $k => $v) {
            $this->assertTrue(is_int($k));
            $this->assertTrue(is_string($v));
        }
        // Test few categories (shouldn't change)
        $this->assertEqual($result[1], 'Adult Entertainment');
        $this->assertEqual($result[11], 'Food and Drink');
        $this->assertEqual($result[30], 'Personal Finance');

        // Cache file shouldn't be created
        $this->assertFalse($oCache->load(true));
        
        // Test creating own cache file
        $data = array( '1' => 'category1');
        $PubConsoleClient->dictionaryData = $data;
        $result = $oPCMarketPluginClient->getAdCategories();
        $this->assertEqual($result, $data);
        // Cache file is created
        $this->assertTrue($oCache->load(false));
        
        // clear cache
        $oCache->clear();
    }
    
    function testGetCreativeTypes()
    {
        $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime'] = 60;

        // Clear var/cache
        $oCache = new OX_oxMarket_Common_Cache('CreativeTypes', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache->setFileNameProtection(false);
        $oCache->clear();
        
        // Prepare test client
        $PubConsoleClient = new PublisherConsoleTestClient();
        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);
        
        // Test bundled var/data/dictionary cache
        $PubConsoleClient->dictionaryData = new Exception('testException');
        $result = $oPCMarketPluginClient->getCreativeTypes();
        $this->assertTrue(is_array($result));
        // There should be at least 30 categories
        $this->assertTrue(count($result)>=9); 
        // All categories have id and description
        foreach ($result as $k => $v) {
            $this->assertTrue(is_int($k));
            $this->assertTrue(is_string($v));
        }
        // Test few categories (shouldn't change)
        $this->assertEqual($result[1], 'Image');
        $this->assertEqual($result[4], 'Video');
        $this->assertEqual($result[9], 'Pop-Under');
        
        // Cache file shouldn't be created
        $this->assertFalse($oCache->load(true));
        
        // Test creating own cache file
        $data = array( '1' => 'creativeType1');
        $PubConsoleClient->dictionaryData = $data;
        $result = $oPCMarketPluginClient->getCreativeTypes();
        $this->assertEqual($result, $data);
        // Cache file is created
        $this->assertTrue($oCache->load(false));
        
        // clear cache
        $oCache->clear();
    }
    
    function testGetCreativeAttributes()
    {
        $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime'] = 60;

        // Clear var/cache
        $oCache = new OX_oxMarket_Common_Cache('CreativeAttributes', 'oxMarket', 
            $GLOBALS['_MAX']['CONF']['oxMarket']['dictionaryCacheLifeTime']);
        $oCache->setFileNameProtection(false);
        $oCache->clear();
        
        // Prepare test client
        $PubConsoleClient = new PublisherConsoleTestClient();
        $oPCMarketPluginClient = new PublisherConsoleMarketPluginTestClient();
        $oPCMarketPluginClient->setPublisherConsoleClient($PubConsoleClient);
        
        // Test bundled var/data/dictionary cache
        $PubConsoleClient->dictionaryData = new Exception('testException');
        $result = $oPCMarketPluginClient->getCreativeAttributes();
        $this->assertTrue(is_array($result));
        // There should be at least 30 categories
        $this->assertTrue(count($result)>=15); 
        // All categories have id and description
        foreach ($result as $k => $v) {
            $this->assertTrue(is_int($k));
            $this->assertTrue(is_string($v));
        }
        // Test few categories (shouldn't change)
        $this->assertEqual($result[1], 'Alcohol');
        $this->assertEqual($result[7], 'Excessive Animation');
        $this->assertEqual($result[15], 'Tobacco');
        
        // Cache file shouldn't be created
        $this->assertFalse($oCache->load(true));
        
        // Test creating own cache file
        $data = array( '1' => 'creativeAttributes1');
        $PubConsoleClient->dictionaryData = $data;
        $result = $oPCMarketPluginClient->getCreativeAttributes();
        $this->assertEqual($result, $data);
        // Cache file is created
        $this->assertTrue($oCache->load(false));
        
        // clear cache
        $oCache->clear();
    }
}