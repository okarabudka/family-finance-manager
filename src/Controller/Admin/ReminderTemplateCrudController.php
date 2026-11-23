<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ReminderFrequency;
use App\Entity\ReminderTemplate;
use App\Entity\ReminderType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

/** @extends AbstractCrudController<ReminderTemplate> */
class ReminderTemplateCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return ReminderTemplate::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('admin.entity.reminder_template.singular')
            ->setEntityLabelInPlural('admin.entity.reminder_template.plural');
    }

    public function configureFields(string $pageName): iterable
    {
        yield TextField::new('name', 'admin.field.name');

        yield AssociationField::new('family', 'admin.field.family')
            ->setRequired(false)
            ->setHelp('admin.help.reminder_family');

        yield ChoiceField::new('type', 'admin.field.type')
            ->setChoices(ReminderType::cases());

        yield ChoiceField::new('frequency', 'admin.field.frequency')
            ->setChoices(ReminderFrequency::cases());

        yield IntegerField::new('sendHour', 'admin.field.send_hour')
            ->setHelp('admin.help.send_hour');

        yield IntegerField::new('dayOfWeek', 'admin.field.day_of_week')
            ->setRequired(false)
            ->setHelp('admin.help.weekly_only');

        yield IntegerField::new('dayOfMonth', 'admin.field.day_of_month')
            ->setRequired(false)
            ->setHelp('admin.help.monthly_only');

        yield TextareaField::new('messageText', 'admin.field.message_text');

        yield BooleanField::new('sendViaTelegram', 'admin.field.send_via_telegram');
        yield BooleanField::new('sendViaEmail', 'admin.field.send_via_email');
        yield BooleanField::new('isActive', 'admin.field.is_active');

        yield DateTimeField::new('lastSentAt', 'admin.field.last_sent_at')
            ->hideOnForm();

        yield DateTimeField::new('createdAt', 'admin.field.created_at')
            ->hideOnForm();
    }
}
