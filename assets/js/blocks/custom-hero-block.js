const { registerBlockType } = wp.blocks;
const { InnerBlocks, InspectorControls } = wp.blockEditor;
const { PanelBody, SelectControl } = wp.components;

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

// Custom Hero Block
registerBlockType("custom-block-plugin/custom-hero-block", {
	title: "Custom Hero Block",
	icon: "shield",
	category: "common",
	version: "0.1.1",
	attributes: {
		imageSize: {
			type: "string",
			default: "cover"
		}
	},

	edit: (props) => {
		const {
			attributes: { imageSize },
			setAttributes,
		} = props;

		return (
			<section className="hero-general-section">
				<InspectorControls>
					<PanelBody title="Image Settings">
						<SelectControl
							label="Image Fit"
							value={imageSize}
							options={[
								{ label: 'Cover', value: 'cover' },
								{ label: 'Contain', value: 'contain' },
							]}
							onChange={(value) => setAttributes({ imageSize: value })}
						/>
					</PanelBody>
				</InspectorControls>

				<div className={`hero-general__image-box ${imageSize === 'contain' ? 'hero-general-contained-image' : ''}`}>
					<InnerBlocks
						allowedBlocks={ALLOWED_BLOCKS}
						template={TEMPLATE}
						templateLock={false}
					/>
				</div>
			</section>
		);
	},

	save: (props) => {
		const {
			attributes: { imageSize },
		} = props;

		return (
			<section className="hero-general-section">
				<div className={`hero-general__image-box ${imageSize === 'contain' ? 'hero-general-contained-image' : ''}`}>
					<InnerBlocks.Content 
						allowedBlocks={ALLOWED_BLOCKS}
						template={TEMPLATE}
						templateLock={false}
					/>
				</div>
			</section>
		);
	},
});