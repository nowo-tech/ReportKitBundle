<?php

declare(strict_types=1);

namespace Nowo\ReportKitBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function is_string;
use function preg_match;

/**
 * Strict configuration tree of `nowo_report_kit` (contract 221-C §7). Every key exists from S0 so hosts can write the
 * final configuration now; S0 itself registers nothing.
 */
final class Configuration implements ConfigurationInterface
{
    /** Owner types and report type keys. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /**
     * The `nowo_report_kit` tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(NowoReportKitExtension::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->children()
                ->booleanNode('enabled')->defaultFalse()->info('Nothing is registered while false')->end()
                ->append(ConfigNodes::doctrine())
                ->arrayNode('owner')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('type')->defaultValue('user')
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match(self::KEY_PATTERN, $value) !== 1)
                                ->thenInvalid('Expected an owner type matching ^[a-z][a-z0-9_]{0,31}$, got %s.')
                            ->end()
                        ->end()
                        ->scalarNode('class')->defaultNull()->info('Host user class of the default Doctrine owner resolver')->end()
                        ->scalarNode('id_field')->defaultValue('id')->cannotBeEmpty()->end()
                        ->append(ConfigNodes::service('resolver', 'Owner resolver service; null = Doctrine resolver'))
                        ->append(ConfigNodes::service('directory', 'People directory service (name, e-mail, locale, time zone)'))
                    ->end()
                ->end()
                ->arrayNode('types')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('order')->info('Menu order: keys of tagged report types')->scalarPrototype()->cannotBeEmpty()->end()->end()
                        ->arrayNode('disabled')->info('Keys of tagged report types to hide')->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->end()
                ->end()
                ->arrayNode('scope')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::service('resolver', 'Scope resolver service (contexts a viewer may pick)'))
                        ->append(ConfigNodes::service('access_checker', 'Report access checker service'))
                    ->end()
                ->end()
                ->append($this->formats())
                ->scalarNode('filename_prefix')->defaultValue('report')
                    ->validate()
                        ->ifTrue(static fn (mixed $value): bool => !is_string($value) || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1)
                        ->thenInvalid('Expected a file name prefix of lower-case letters, digits, "-" and "_" (e.g. "report"), got %s.')
                    ->end()
                ->end()
                ->arrayNode('brand')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::service('resolver', 'Brand resolver service (logo, colours, name per context)'))
                    ->end()
                ->end()
                ->arrayNode('limits')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('cache_pool')->defaultValue('cache.rate_limiter')->cannotBeEmpty()->end()
                        ->append(ConfigNodes::rate('exports', 20, '10 minutes'))
                        ->append(ConfigNodes::rate('views', 60, '1 minute'))
                        ->integerNode('max_rows_per_section')->defaultValue(50000)->min(1)->max(1000000)->end()
                        ->integerNode('pdf_max_rows')->defaultValue(120)->min(1)->max(10000)->end()
                        ->integerNode('pdf_max_cell')->defaultValue(180)->min(10)->max(10000)->end()
                        ->integerNode('render_seconds')->defaultValue(25)->min(1)->max(300)->end()
                        ->integerNode('max_pdf_html_bytes')->defaultValue(5242880)->min(1024)->max(104857600)->end()
                    ->end()
                ->end()
                ->arrayNode('subscriptions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('max_per_owner')->defaultValue(10)->min(1)->max(100)->end()
                        ->arrayNode('frequencies')
                            ->requiresAtLeastOneElement()
                            ->defaultValue(['daily', 'weekly', 'monthly'])
                            ->enumPrototype()->values(['daily', 'weekly', 'monthly'])->end()
                        ->end()
                        ->integerNode('default_hour')->defaultValue(7)->min(0)->max(23)->info('In the owner time zone (Q-O5)')->end()
                        ->booleanNode('allow_hour_choice')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('schedule')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->append(ConfigNodes::cron('cron', '5 * * * *'))
                        ->append(ConfigNodes::timezone('default_timezone', 'UTC'))
                        ->integerNode('batch')->defaultValue(200)->min(1)->max(5000)->end()
                        ->integerNode('max_failures')->defaultValue(3)->min(1)->max(100)->end()
                        ->integerNode('stale_claim_minutes')->defaultValue(30)->min(1)->max(1440)->end()
                    ->end()
                ->end()
                ->arrayNode('mail')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::auto('enabled', ConfigNodes::AUTO))
                        ->scalarNode('template')->defaultValue('@NowoReportKit/email/scheduled_report.html.twig')->cannotBeEmpty()->end()
                        ->integerNode('max_attachment_bytes')->defaultValue(8388608)->min(0)->max(52428800)->end()
                    ->end()
                ->end()
                ->arrayNode('links')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::baseUrl('base_url'))
                    ->end()
                ->end()
                ->arrayNode('retention')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('run_days')->defaultValue(90)->min(1)->max(3650)->end()
                        ->integerNode('batch_size')->defaultValue(500)->min(1)->max(5000)->end()
                    ->end()
                ->end()
                ->arrayNode('messenger')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('max_seconds')->defaultValue(50)->min(1)->max(3600)->end()
                    ->end()
                ->end()
                ->arrayNode('bridges')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::auto('user_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('notify_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('audit_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('mail_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('controller_kit', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('api_token', ConfigNodes::AUTO))
                        ->append(ConfigNodes::auto('instance_settings', false))
                    ->end()
                ->end()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->append(ConfigNodes::path('path_prefix', '/reports'))
                        ->scalarNode('layout_template')->defaultNull()->end()
                        ->arrayNode('templates')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('index')->defaultNull()->end()
                                ->scalarNode('show')->defaultNull()->end()
                                ->scalarNode('sections')->defaultNull()->end()
                                ->scalarNode('schedule_dialog')->defaultNull()->end()
                            ->end()
                        ->end()
                        ->arrayNode('security')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('access_roles')
                                    ->requiresAtLeastOneElement()
                                    ->defaultValue(['ROLE_USER'])
                                    ->scalarPrototype()->cannotBeEmpty()->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->append(ConfigNodes::adminUi('/admin/report-subscriptions'))
                ->append(ConfigNodes::api())
                ->append(ConfigNodes::service('settings_provider', 'Settings provider service; null = configuration values'))
            ->end();

        return $treeBuilder;
    }

    /**
     * `formats`: CSV always available; XLSX and PDF need their suggested engines (Q-O3); JSON is for the API.
     */
    private function formats(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('formats');
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('csv')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('xlsx')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::auto('enabled', ConfigNodes::AUTO))
                    ->end()
                ->end()
                ->arrayNode('pdf')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append(ConfigNodes::auto('enabled', ConfigNodes::AUTO))
                        ->enumNode('paper')->values(['A4', 'A3', 'letter', 'legal'])->defaultValue('A4')->end()
                        ->scalarNode('template')->defaultValue('@NowoReportKit/pdf/report.html.twig')->cannotBeEmpty()->end()
                    ->end()
                ->end()
                ->arrayNode('json')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->info('API only')->end()
                    ->end()
                ->end()
            ->end();

        return $node;
    }
}
