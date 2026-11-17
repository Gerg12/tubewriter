const { registerBlockType } = wp.blocks;
const { InnerBlocks } = wp.blockEditor;

const ALLOWED_BLOCKS = [
	'core/image',
	'core/group',
	'core/heading',
	'core/paragraph',
	'core/buttons',
	'core/button'
];

const TEMPLATE = [
	['core/image', {
		className: 'hero-general__background-image'
	}],
	['core/group', {
		className: 'hero-general-content'
	}, [
		['core/heading', {
			level: 1,
			className: 'hero-general__title',
			placeholder: 'Enter main title...'
		}],
		['core/heading', {
			level: 2,
			className: 'hero-general__subtitle',
			placeholder: 'Enter subtitle...'
		}],
		['core/paragraph', {
			className: 'hero-general__text',
			placeholder: 'Enter description text...'
		}],
		['core/buttons', {
			className: 'hero-general__button-box'
		}, [
			['core/button', {
				className: 'hero-general__button'
			}]
		]]
	]]
];

registerBlockType('custom-block-plugin/hero-slide', {
	title: 'Hero Slide',
	icon: 'slides',
	category: 'common',
	parent: ['custom-block-plugin/hero-sliderk'],
	supports: {
		inserter: false,
	},
	edit: () => (
		<div className="hero-slide">
			<InnerBlocks
				allowedBlocks={ALLOWED_BLOCKS}
				template={TEMPLATE}
				templateLock={false}
			/>
		</div>
	),
	save: () => (
		<div className="hero-slide">
			<InnerBlocks.Content />
		</div>
	),
}); 