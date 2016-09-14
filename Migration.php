<?php

abstract class Migration extends Wire{

	public static $description;

	abstract public function update();

	abstract public function downgrade();


	/**
	 * Cycle over a group of pages without running into memory exhaustion
	 *
	 * @param string   $selector
	 * @param callable $callback
	 * @return int     $num
	 */
	protected function eachPageUncache($selector, callable $callback)
	{
		$num = 0;
		$id = 0;
		while (true) {
			$p = $this->pages->get("{$selector}, id>$id");
			if(!$id = $p->id) break;
			$callback($p);
			$this->pages->uncacheAll($p);
			$num++;
		}
		return $num;
	}


	/**
	 * Insert a field into a template optionally at a specific position.
	 *
	 * @param Template|string   $template
	 * @param Field|string      $field
	 * @param Field|string|null $reference
	 * @param bool              $after
	 * @throws WireException
	 */
	protected function insertIntoTemplate ($template, $field, $reference = null, $after = true)
	{
		$template = $this->getTemplate($template);
		$fieldgroup = $template->fieldgroup;
		$method = $after ? 'insertAfter' : 'insertBefore';
		$field = $this->getField($field);

		// Get reference if supplied
		if($reference instanceof Field)
			$reference = $fieldgroup->get($reference->name);
		else if(is_string($reference))
			$reference = $fieldgroup->get($reference);

		// Insert field or append
		if($reference instanceof Field)
			$fieldgroup->$method($field, $reference);
		else
			$fieldgroup->append($field);

		$fieldgroup->save();
	}


	/**
	 * Removes a field from a template.
	 * Also removes all data for that field from pages using the template.
	 *
	 * @param Template|string   $template
	 * @param Field|string      $field
	 * @return bool     $success (true => success, false => failure)
	 * @throws WireException
	 */
	protected function removeFromTemplate($template, $field) {
		$t = $this->getTemplate($template);
		$f = $this->getField($field);
		$success = $t->fieldgroup->remove($f);
		$t->fieldgroup->save();
		return $success;
	}


	/**
	 * Removes a field from a set of templates.
	 * Also removes all data for that field from pages using any of the named templates.
	 *
	 * @param array|string   $template_names
	 * @param Field|string   $field
	 * @return array Array of template names from which the field was successfully removed
	 * @throws WireException
	 * @example removerFromTemplates('home,blog', 'first_name');
	 * @example removerFromTemplates(['home','blog'], 'first_name');
	 */
	protected function removeFromTemplates($template_names, $field) {
		$removed_names = array();
		if (is_string($template_names)) {
			$template_names = explode(',', $template_names);
		}

		if (empty($template_names)) $removed_names;

		foreach ($template_names as $tname) {
			if ($this->removeFromTemplate($tname, $field)) $removed_names[$tname] = $tname;
		}

		return $removed_names;
	}


	/**
	 * (re) labels the named field.
	 *
	 * @param Field|string $field The field to be re-labelled
	 * @param string       $label The new label for the field
	 * @throws WireException
	 */
	protected function labelField($field, $label)
	{
		$f = $this->getField($field);
		$f->label = $label;
		$f->save();
	}


	/**
	 * Deletes a field
	 *
	 * @param Field|string $field The field to be deleted
	 * @throws WireException
	 */
	protected function deleteField($field)
	{
		$field = $this->getField($field);
		$fgs   = $field->getFieldgroups();

		foreach($fgs as $fg){
			$fg->remove($field);
			$fg->save();
		}

		$this->fields->delete($field);
	}


	/**
	 * Conditionally deletes a field IFF it is not in use in any templates
	 *
	 * @param Field|string $field The field to be deleted
	 * @return bool true => deleted, false => could not delete.
	 * @throws WireException
	 */
	protected function deleteFieldIfUnused($field)
	{
		$f = $this->getField($field);
		$t = $f->getTemplates();
		if (0 == $t->count()) {
			$this->fields->delete($f);
			$this->message("Deleted field '{$f->name}'");
			return true;
		} else {
			$this->warning("Field '{$f->name}' is still present in templates [$t]");
			return false;
		}
	}


	/**
	 * Checks if the strings in the given array are valid, unused, field names.
	 *
	 * The check will fail for a candidate fieldname if it changes when sanitised as a fieldname or if the name is
	 * already in use as a field.
	 *
	 * @param array $candidates Strings to check for validity as field names
	 * @param bool  $quiet Should warnings of failures be surpressed? default: true
	 * @return array $rejects Array of candidate => sanitised candidate names.
	 *
	 * Use 'array_key_exists()' when checking return values.
	 */
	protected function verifyCandidateFieldNames(array $candidates, $quiet = true)
	{
		$rejects = array();
		$fields_info = $this->fields->getAll();
		foreach ($candidates as $candidate) {
			$sanitised = $this->sanitizer->fieldName($candidate);

			if (empty($candidate)) {
				$rejects[$candidate] = $candidate;
				if (!$quiet) $this->warning("Candidate fieldname cannot be empty.");
				continue;
			}

			if ($sanitised !== $candidate) {
				$rejects[$candidate] = $sanitised;
				if (!$quiet) $this->warning("Candidate fieldname '$candidate' sanitized to '$sanitised'");
				continue;
			}

			if ($fields_info->has($candidate)) {
				$rejects[$candidate] = $sanitised;
				if (!$quiet) $this->warning("Candidate fieldname '$candidate' already in use.");
				continue;
			}

			if ($this->fields->isNative($candidate)) {
				$rejects[$candidate] = $sanitised;
				if (!$quiet) $this->warning("Candidate fieldname '$candidate' is a native (system) field name.");
			}
		}

		if (!empty($rejects) && !$quiet) throw new WireException("Error in candidate fieldnames, please correct.");
		return $rejects;
	}


	/**
	 * Edit a field in template context
	 *
	 * @param Template|string $template
	 * @param Field|string    $field
	 * @param callable        $callback
	 */
	protected function editInTemplateContext ($template, $field, callable $callback)
	{
		$template = $this->getTemplate($template);
		$fieldgroup = $template->fieldgroup;
		$field = $this->getField($field);

		$context = $fieldgroup->getField($field->name, true);
		$callback($context, $template);
		$this->fields->saveFieldgroupContext($context, $fieldgroup);
	}


	/**
	 * @param Template|string $template
	 * @return Template
	 * @throws WireException
	 */
	protected function getTemplate ($template)
	{
		$template = !is_string($template) ? $template : $this->templates->get($template);
		if(!$template instanceof Template) throw new WireException("Invalid template $template");
		return $template;
	}


	/**
	 * @param Field|string $field
	 * @return Field
	 * @throws WireException
	 */
	protected function getField ($field)
	{
		$field = !is_string($field) ? $field : $this->fields->get($field);
		if(!$field instanceof Field) throw new WireException("Invalid field $field");
		return $field;
	}


	/**
	 * Renames a field
	 *
	 * @param Field|string $field
	 * @param string $new_name
	 * @throws WireException
	 */
	protected function renameField($field, $new_name)
	{
		if (!is_string($new_name)) throw new WireException("New name must be a string");
		$new_name = $this->sanitizer->fieldName($new_name);
		if (empty($new_name)) throw new WireException("New name is not a valid field name");

		$exists = $this->fields->get($new_name);
		if ($exists instanceof Field) throw new WireException("A field called $new_name already exists");

		$f = $this->getField($field);
		$old_name = $f->name;
		$f->name = $new_name;
		$f->save();
		$this->message("Renamed '$old_name' to '$new_name'.");
	}


	/**
	 * Renames a set of fields
	 *
	 * @param array $conversions An array of existing field => new field name mappings.
	 * @throws WireException
	 * @example renameFields(['text_2'=>'lead_text', $f=>'about']);
	 */
	protected function renameFields(array $conversions)
	{
		if (!empty($conversions)) {
			foreach ($conversions as $from => $to) {
				$this->renameField($from, $to);
			}
		}
	}


	/**
	 * Within the given templates, replaces each named source field with its replacement field and copies over data from
	 * all pages that use the templates.
	 * It also copies the source fields' template context settings to the replacement.
	 *
	 * @param array $templates templates in which to perform the replacement and data copy
	 * @param array $replacements "source field name" -> "replacement field name" mappings
	 * @param bool $remove_source_from_template Default: false. When true, causes the source fields to be removed from templates if the data copied over successfully
	 * @param bool $clone_missing_replacements When true (default) if the replacement field does not yet exist, it will be cloned from the source field.
	 * @throws WireException
	 * @example replaceFieldsInTemplates([home, blog], ['t_area_1'=>'lead', 't_area_2'=>'body']);
	 */
	protected function replaceFieldsInTemplates(array $templates, array $replacements, $remove_source_from_template=false, $clone_missing_replacements=true)
	{
		if (!is_array($templates)) throw new WireException("The \$templates argument must be an array of template names.");
		if (empty($templates)) {
			$this->message("No templates specified - all done in " . __METHOD__);
			return;
		}

		if (!is_array($replacements)) throw new WireException("The \$templates argument must be an array of template names.");
		if (empty($replacements)) {
			$this->message("No replacements specified - all done in " . __METHOD__);
			return;
		}

		// Check all the source fields exist and appear in all the templates we are replacing them in...
		foreach ($replacements as $source_name => &$replacement_name) {
			$fields_info = $this->fields->getAll();
			if (empty($replacement_name)) {
				throw new WireException("Replacement field is empty!");
			}
			if (empty($source_name)) {
				throw new WireException("Source field is empty!");
			}

			// Sanitize the names...
			$source_name	  = $this->sanitizer->fieldName($source_name);
			$replacement_name = $this->sanitizer->fieldName($replacement_name);

			if ($source_name == $replacement_name) {
				throw new WireException("Source and replacement fields cannot match!");
			}

			if (!$fields_info->has($source_name)) {
				throw new WireException("Source field '$source_name' does not exist!");
			}

			// Clone any missing replacement fields...
			if ($clone_missing_replacements && !$fields_info->has($replacement_name)) {
				$this->cloneFieldAndRename($source_name, $replacement_name);
			}
		}


		// Add replacement field to templates and copy data from source field in pages using the templates
		foreach ($replacements as $source_name => $replacement_name) {
			$src	= $this->getField($source_name);
			$src_id = $src->id;

			try {
				$dst = $this->getField($replacement_name);
			} catch (WireException $e) {
				$this->warning("Skipping $source_name => $replacement_name replacement because field '$replacement_name' does not exist.");
				continue; // Try next replacement - this one can't complete!
			}

			$dst_id = $dst->id;
			$this->message("Source field id [$src_id], replacement field id [$dst_id]");
			foreach ($templates as $tname) {

				try {
					// Add the replacement field into the template, just after the source field...
					$this->insertIntoTemplate($tname, $replacement_name, $source_name);
				} catch (WireException $e) {
					$this->warning("Could not insert '$replacement_name' into template '$tname' after field '$source_name' - skipping context and data copy");
					continue;
				}

				// Clone the source field's context into the replacement field's context..
				$fieldgroup	 = $this->getTemplate($tname)->fieldgroup;
				$src_context	= $fieldgroup->getFieldContextArray($src_id);
				if (!empty($src_context)) {
					$fieldgroup->setFieldContextArray($dst_id, $src_context);
					$fieldgroup->saveContext();
					$this->message("Cloned '$source_name' to '$replacement_name' context for template '$tname'.");
				}

				// Do the data copy...
				$num_copied = $this->copyFieldInPagesUsingTemplates(array($tname), $source_name, $replacement_name);

				if (false == $remove_source_from_template) continue; // No need to remove source field from template

				$match = true;
				if ($num_copied > 0) {
					// Check the copy worked. If it did, allow the source field's removal from the template
					$src_pages = $this->pages->find("template=$tname, $source_name!='', include=all");
					$dst_pages = $this->pages->find("template=$tname, $replacement_name!='', include=all");
					if (((string)$src_pages == (string)$dst_pages)) {
						// The src and dst page ids match - but do the data in the fields?
						foreach ($src_pages as $p) {
							if ($p->$source_name != $p->$replacement_name) {
								$match = false;
								break;
							}
						}
					}
				}

				if ($match) {
					$this->message("Removing '$source_name' from template '$tname' as page data copied ok");
					$this->removeFromTemplate($tname, $source_name);
				} else {
					$this->warning("Could not remove '$source_name' from template '$tname' because some of the page data did not copy correctly.");
				}
			}
		}

	}


	/**
	 * For every page using any of the given templates, copy the value from the source field to the dest field.
	 *
	 * @param array $templates     The names of the templates to use to find pages in which the data copy will take place
	 * @param string|Field $source The field to copy values from
	 * @param string|Field $dest   The field to copy values to
	 * @param bool $quiet          Should the page's modified date/user be changed? true (default) -> no, false -> yes.
	 * @return integer             Number of pages updated
	 * @throws WireException
	 */
	protected function copyFieldInPagesUsingTemplates(array $templates, $source, $dest, $quiet = true)
	{
		$s = $this->getField($source);
		$sourcename = $s->name; // Now a string
		$d = $this->getField($dest);
		$destname = $d->name;
		$selector = "template='".implode('|', $templates)."', include=all";
		$num = $this->eachPageUncache($selector, function ($p) use ($sourcename, $destname, $quiet) {
			$p->of(false);
			$p->$destname = $p->$sourcename;
			$p->save(array('quiet' => $quiet));
		});
		$this->message("Copied '$sourcename' to '$destname' in $num pages using selector [$selector]");
		return $num;
	}


	/**
	 * Clones an existing field, giving the clone a new name.
	 *
	 * @param Field|string $source_field The field to be cloned
	 * @param string       $new_name     The name to use for the new field cloned from the source
	 * @throws WireException
	 */
	protected function cloneFieldAndRename($source_field, $new_name)
	{
		// Check the new name is valid
		if (!is_string($new_name)) throw new WireException("New name must be a string");
		$new_name = $this->sanitizer->fieldName($new_name);
		if (empty($new_name)) throw new WireException("New name is not a valid field name");

		// Check a field using the new name does not already exist
		$exists = $this->fields->get($new_name);
		if ($exists instanceof Field) throw new WireException("A field called $new_name already exists");

		// Check the source field exists
		$source = $this->getField($source_field);
		$new_field = $this->fields->clone($source, $new_name);
		$this->fields->save($new_field);
		$this->message("Successfully cloned field '{$source->name}' to '{$new_field->name}'");
	}


	/**
	 * Creates a named snapshot of the database.
	 * Uses the built-in WireBackup class
	 *
	 * @param string $name The name of the snapshot to create (without path or extension)
	 * @return bool A value of true means the restore was a success.
	 * @throws WireException
	 */
	protected function makeDatabaseSnapshot($name)
	{
		$p = $this->config->paths->assets.'backups/database/';

		// Look for the named db file...
		$f = "{$p}$name.sql";
		if (is_readable($f)) {
			throw new WireException("DB backup file $f already exists - please use it or delete it.");
		}

		$backup = new WireDatabaseBackup($p);
		$backup->setDatabase($this->database);

		$options = array(
			'filename'    => "$name.sql",
			'description' => "Made as part of migration script.",
		);
		$file = $backup->backup($options);
		if ($file) {
			$this->message("Created DB snapshot '$name.sql'");
		} else {
			$this->error(implode("\n", $backup->errors()));
			throw new WireException("Could not backup DB.");
		}
	}


	/**
	 * Restores the named DB snapshot
	 * Uses the built-in WireBackup class
	 *
	 * NB: You need to remove any fields you might have added before you restore a DB snapshot. This is needed because
	 * the snapshot will *not* overwrite the field_yourfieldname table that was created for your field, leaving it
	 * orphaned in the DB once the fields table from the snapshot is resotred.
	 *
	 * @param string $name The name of the snapshot to restore (without path or extension)
	 * @return bool A value of true means the restore was a success.
	 * @throws WireException
	 */
	protected function restoreDatabaseSnapshot($name)
	{
		$p = $this->config->paths->assets.'backups/database/';
		// Look for the named db file...
		$f = "{$p}$name.sql";
		if (!file_exists($f)) {
			throw new WireException("DB backup file $f not found - can't downgrade.");
		}
		if (!is_readable($f)) {
			throw new WireException("DB backup file $f cannot be read - please change permissions and try again.");
		}

		$backup = new WireDatabaseBackup($p);
		$backup->setDatabase($this->database); // optional, if ommitted it will attempt it's own connection
		$success = $backup->restore("$name.sql");
		if ($success) {
			$this->message("Restored DB snapshot '$name.sql'");
			$this->message(implode("\n", $backup->notes()));
		} else {
			$this->error(implode("\n", $backup->errors()));
			throw new WireException("Could not restore DB.");
		}

		return $success;
	}


}
