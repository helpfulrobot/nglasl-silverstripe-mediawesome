<?php

/**
 *	Mediawesome CMS attribute for a media type.
 *	@author Nathan Glasl <nathan@silverstripe.com.au>
 */

class MediaAttribute extends DataObject
{

    private static $db = array(
        'OriginalTitle' => 'Varchar(255)',
        'Title' => 'Varchar(255)',
        'Content' => 'HTMLText',
        'LinkID' => 'Int'
    );

    private static $has_one = array(
        'MediaPage' => 'MediaPage'
    );

    private static $default_sort = 'Title';

    /**
     *	Flag a write occurrence to prevent infinite recursion.
     */

    private static $write_flag = false;

    /**
     *	Allow access for CMS users viewing attributes.
     */

    public function canView($member = null)
    {
        return true;
    }

    /**
     *	Determine access for the current CMS user editing attributes.
     */

    public function canEdit($member = null)
    {
        return $this->checkPermissions($member);
    }

    /**
     *	Determine access for the current CMS user creating attributes.
     */

    public function canCreate($member = null)
    {
        return $this->checkPermissions($member);
    }

    /**
     *	Restrict access for CMS users deleting attributes.
     */

    public function canDelete($member = null)
    {
        return false;
    }

    /**
     *	Determine access for the current CMS user from the site configuration permissions.
     *
     *	@parameter <{CURRENT_MEMBER}> member
     *	@return boolean
     */

    public function checkPermissions($member = null)
    {

        // Retrieve the current site configuration permissions for customisation of media.

        $configuration = SiteConfig::current_site_config();
        return Permission::check($configuration->MediaPermission, 'any', $member);
    }

    /**
     *	Display the appropriate CMS media attribute fields.
     */

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        $fields->removeByName('OriginalTitle');

        // Remove the attribute fields relating to an individual media page.

        $fields->removeByName('Content');
        $fields->removeByName('LinkID');
        $fields->removeByName('MediaPageID');

        // Allow extension customisation.

        $this->extend('updateMediaAttributeCMSFields', $fields);
        return $fields;
    }

    /**
     *	Confirm that the current attribute is valid.
     */

    public function validate()
    {
        $result = parent::validate();

        // Confirm that the current attribute has been given a title.

        if ($result->valid() && !$this->Title) {
            $result->error('"Title" required!');
        }

        // Allow extension customisation.

        $this->extend('validateMediaAttribute', $result);
        return $result;
    }

    /**
     *	Assign the current attribute to each media page of the respective type.
     */

    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // Set the original title of the current attribute for use in templates.

        if (is_null($this->OriginalTitle)) {
            $this->OriginalTitle = $this->Title;
        }

        // Retrieve the respective media type for updating all attribute references.

        $parameters = Controller::curr()->getRequest()->requestVars();
        $matches = array();
        $result = preg_match('#TypesAttributes/item/[0-9]*/#', $parameters['url'], $matches);
        if ($result) {
            $ID = preg_replace('#[^0-9]#', '', $matches[0]);
            $pages = MediaPage::get()->innerJoin('MediaType', 'MediaPage.MediaTypeID = MediaType.ID')->where('MediaType.ID = ' . (int)$ID);

            // Apply this new attribute to existing media pages of the respective type.

            if ($pages && (is_null($this->MediaPageID) || ($this->MediaPageID === 0))) {
                foreach ($pages as $key => $page) {
                    if ($key === 0) {

                        // Apply the current attribute to the first media page.

                        self::$write_flag = true;
                        $this->LinkID = -1;
                        $this->MediaPageID = $page->ID;
                        $page->MediaAttributes()->add($this);
                    } else {

                        // Create a new attribute for remaining media pages.

                        $new = MediaAttribute::create();
                        $new->Title = $this->Title;
                        $new->LinkID = $this->ID;
                        $new->MediaPageID = $page->ID;
                        $page->MediaAttributes()->add($new);
                        $new->write();
                    }
                }
            }

            // Apply the changes from this attribute to existing media pages of the respective type.

            elseif ($pages) {

                // Confirm that a write occurrence doesn't already exist.

                if (!self::$write_flag) {
                    foreach ($pages as $page) {
                        foreach ($page->MediaAttributes() as $attribute) {

                            // Confirm that each attribute is linked to the original attribute.

                            if (($attribute->LinkID == $this->ID) && ($attribute->Title !== $this->Title)) {

                                // Apply the changes from this attribute.

                                self::$write_flag = true;
                                $attribute->Title = $this->Title;
                                $attribute->write();
                            }
                        }
                    }
                    self::$write_flag = false;
                }
            }
        }
    }

    /**
     *	Retrieve a class name of the current attribute for use in templates.
     *
     *	@return string
     */

    public function getTemplateClass()
    {
        return strtolower($this->OriginalTitle);
    }

    /**
     *	Retrieve the title and content of the current attribute for use in templates.
     *
     *	@return string
     */

    public function forTemplate()
    {

        // Add spaces between words, other characters and numbers.

        return ltrim(preg_replace(array(
            '/([A-Z][a-z]+)/',
            '/([A-Z]{2,})/',
            '/([_.0-9]+)/'
        ), ' $0', $this->Title))  . ": {$this->Content}";
    }
}
