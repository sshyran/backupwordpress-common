<?php

class Test_Main_Functions extends WP_UnitTestCase {

	protected $plugin;

	function setUp() {
		parent::setUp();
		$this->plugin = new HM\BackUpWordPress\Addon( '2.0.4', '3.1.4', 'HM\\BackUpWordPress\\S3BackUpService', HM\BackUpWordPress\Plugin::get_instance() );
	}

	function test_instantiation() {

		$this->assertInstanceOf( '\\HM\\BackUpWordPress\\Addon', $this->plugin );

		$this->assertEquals( 10, has_action( 'admin_init', array( $this->plugin, 'maybe_self_deactivate' ) ) );

	}
}
