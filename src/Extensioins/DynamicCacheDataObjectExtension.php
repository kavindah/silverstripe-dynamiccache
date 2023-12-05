<?php

namespace TractorCow\DynamicCache;

use SilverStripe\Versioned\Versioned;
use SilverStripe\ORM\DataExtension;

/**
 * Ensures that dataobjects are correctly flushed from the cache on save
 *
 * @author Damian Mooyman
 * @package dynamiccache
 */


class DynamicCacheDataObjectExtension extends DataExtension
{

    /**
     * Clear the entire dynamic cache once a dataobject has been saved.
     * Safe and dirty.
     *
     */
    public function onAfterWrite()
    {
        if (!DynamicCacheMiddleware::config()->cacheClearOnWrite) {
            return;
        }

        // Do not clear cache if object is Versioned. Only clear
        // when a user publishes.
        //
        // DynamicCacheControllerExtension already opts out of caching if
        // on ?stage=Stage so this behaviour makes sense.
        //
        if ($this->hasLiveStage()) {
            return;
        }

        DynamicCacheMiddleware::inst()->clear();
    }

    /**
     * Clear the entire dynamic cache once a dataobject has been deleted.
     * Safe and dirty.
     *
     */
    public function onBeforeDelete()
    {
        if (!DynamicCacheMiddleware::config()->cacheClearOnWrite) {
            return;
        }
        DynamicCacheMiddleware::inst()->clear();
    }

    /**
     * Support Versioned::publish()
     * - Use case: SheaDawson Blocks module support
     */
    public function onBeforeVersionedPublish()
    {
        if (!DynamicCacheMiddleware::config()->cacheClearOnWrite) {
            return;
        }
        DynamicCacheMiddleware::inst()->clear();
    }

    /**
     * Support SiteTree::doPublish()
     */
    public function onAfterPublish()
    {
        if (!DynamicCacheMiddleware::config()->cacheClearOnWrite) {
            return;
        }
        DynamicCacheMiddleware::inst()->clear();
    }

    /**
     * Support HeyDay's VersionedDataObject extension
     * - Use case: DNADesign Elemental support
     */
    public function onAfterVersionedPublish()
    {
        if (!DynamicCacheMiddleware::config()->cacheClearOnWrite) {
            return;
        }
        DynamicCacheMiddleware::inst()->clear();
    }

    protected function hasLiveStage()
    {
        $class = get_class($this->owner);
        // NOTE: Using has_extension over hasExtension as the former
        //       takes subclasses into account.
        $hasVersioned = $class::has_extension(Versioned::class);
        if (!$hasVersioned) {
            return false;
        }
        $stages = $this->owner->getVersionedStages();
        return $stages && in_array('Live', $stages);
    }
}
